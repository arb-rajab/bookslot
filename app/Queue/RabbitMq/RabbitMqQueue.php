<?php

namespace App\Queue\RabbitMq;

use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Queue as QueueBase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

/**
 * D-0047 (docs/project-memory/09-decision-log.md): a real AMQP broker
 * (RabbitMQ) as this project's queue transport, wired directly to
 * php-amqplib rather than through a third-party Laravel-RabbitMQ package —
 * deliberately, so this integration is a real, readable, from-first-
 * principles demonstration of message-queue architecture (delayed
 * delivery, retry-with-backoff, and dead-lettering), not a black box.
 *
 * Delayed delivery (later()/release($delay)) uses the standard RabbitMQ
 * "TTL + dead-letter exchange" pattern, since RabbitMQ's own core has no
 * native delayed-message primitive: a message is published into a
 * `{queue}.delay` queue with a per-message `expiration` (D-0047 names the
 * one accepted limitation of this exact pattern — RabbitMQ only expires a
 * per-message TTL in head-of-queue order, so a very short delay published
 * after a much longer one already queued will not jump ahead of it. Never
 * consumed directly, `{queue}.delay` dead-letters each expired message back
 * to the default exchange with the original queue's name as routing key —
 * which, on the default exchange, delivers straight to that queue with no
 * custom exchange needed anywhere in this design.
 *
 * Retry attempts are tracked with an explicit `x-bookslot-attempt` message
 * header this class manages itself (not RabbitMQ's own `x-death` array,
 * whose "count" conflates every reason a message was dead-lettered) — set
 * on release(), read by RabbitMqJob::attempts(), so Laravel's own
 * max-tries/backoff logic in CallQueuedHandler works unmodified.
 */
class RabbitMqQueue extends QueueBase implements QueueContract
{
    private AMQPStreamConnection $connection;

    private AMQPChannel $channel;

    /** @var array<string, true> */
    private array $declaredQueues = [];

    private string $defaultQueueName;

    private int $retryAfterSeconds;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config)
    {
        $this->connection = new AMQPStreamConnection(
            $config['host'],
            $config['port'],
            $config['user'],
            $config['password'],
            $config['vhost'] ?? '/',
            insist: false,
            connection_timeout: (float) ($config['connect_timeout'] ?? 5.0),
            read_write_timeout: (float) ($config['read_write_timeout'] ?? 5.0),
        );

        $this->channel = $this->connection->channel();
        $this->defaultQueueName = $config['queue'] ?? 'default';
        $this->retryAfterSeconds = (int) ($config['retry_after'] ?? 90);
    }

    public function size($queue = null): int
    {
        $queue = $this->resolveQueueName($queue);
        $this->ensureQueueDeclared($queue);

        [, $messageCount] = $this->channel->queue_declare($queue, passive: true);

        return $messageCount;
    }

    public function push($job, $data = '', $queue = null): mixed
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $this->resolveQueueName($queue), $data),
            $queue,
            null,
            fn ($payload, $queue) => $this->publishNow($payload, $this->resolveQueueName($queue)),
        );
    }

    public function pushOn($queue, $job, $data = ''): mixed
    {
        return $this->push($job, $data, $queue);
    }

    public function pushRaw($payload, $queue = null, array $options = []): mixed
    {
        return $this->publishNow($payload, $this->resolveQueueName($queue));
    }

    public function later($delay, $job, $data = '', $queue = null): mixed
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $this->resolveQueueName($queue), $data),
            $queue,
            $delay,
            fn ($payload, $queue, $delay) => $this->publishDelayed($payload, $this->resolveQueueName($queue), $this->secondsUntil($delay), 0),
        );
    }

    public function laterOn($queue, $delay, $job, $data = ''): mixed
    {
        return $this->later($delay, $job, $data, $queue);
    }

    /**
     * @param  Collection<array-key, mixed>|array<array-key, mixed>  $jobs
     */
    public function bulk($jobs, $data = '', $queue = null): void
    {
        foreach ((is_array($jobs) ? $jobs : $jobs->all()) as $job) {
            $this->push($job, $data, $queue);
        }
    }

    public function pop($queue = null): ?RabbitMqJob
    {
        $queue = $this->resolveQueueName($queue);
        $this->ensureQueueDeclared($queue);

        $message = $this->channel->basic_get($queue);

        if ($message === null) {
            return null;
        }

        return new RabbitMqJob($this->container, $this, $this->channel, $this->connectionName, $queue, $message);
    }

    /** Re-publishes a job's payload with an incremented attempt header, used by RabbitMqJob::release(). */
    public function republishForRetry(string $payload, string $queue, int $attempt, int $delaySeconds): void
    {
        if ($delaySeconds > 0) {
            $this->publishDelayed($payload, $queue, $delaySeconds, $attempt);
        } else {
            $this->publishNow($payload, $queue, $attempt);
        }
    }

    private function publishNow(string $payload, string $queue, int $attempt = 0): string
    {
        $this->ensureQueueDeclared($queue);

        $message = $this->buildMessage($payload, $attempt);

        $this->channel->basic_publish($message, '', $queue);

        return $message->get('message_id');
    }

    private function publishDelayed(string $payload, string $queue, int $delaySeconds, int $attempt): string
    {
        $delaySeconds = max($delaySeconds, 0);
        $delayQueue = $this->delayQueueName($queue);

        $this->ensureQueueDeclared($queue);
        $this->ensureDelayQueueDeclared($delayQueue, $queue);

        $message = $this->buildMessage($payload, $attempt);
        $message->set('expiration', (string) ($delaySeconds * 1000));

        $this->channel->basic_publish($message, '', $delayQueue);

        return $message->get('message_id');
    }

    private function buildMessage(string $payload, int $attempt): AMQPMessage
    {
        $properties = [
            'content_type' => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'message_id' => (string) Str::uuid(),
        ];

        if ($attempt > 0) {
            $properties['application_headers'] = new AMQPTable(['x-bookslot-attempt' => $attempt]);
        }

        return new AMQPMessage($payload, $properties);
    }

    private function ensureQueueDeclared(string $queue): void
    {
        if (isset($this->declaredQueues[$queue])) {
            return;
        }

        $this->channel->queue_declare($queue, durable: true, auto_delete: false);
        $this->declaredQueues[$queue] = true;
    }

    /**
     * The delay queue dead-letters back to the default exchange with the
     * target queue's own name as routing key — on the default exchange,
     * that routing key IS an implicit binding to that queue, so no custom
     * exchange is declared anywhere in this design (see class docblock).
     */
    private function ensureDelayQueueDeclared(string $delayQueue, string $targetQueue): void
    {
        if (isset($this->declaredQueues[$delayQueue])) {
            return;
        }

        $this->ensureQueueDeclared($targetQueue);

        $this->channel->queue_declare(
            $delayQueue,
            durable: true,
            auto_delete: false,
            arguments: new AMQPTable([
                'x-dead-letter-exchange' => '',
                'x-dead-letter-routing-key' => $targetQueue,
            ]),
        );

        $this->declaredQueues[$delayQueue] = true;
    }

    private function delayQueueName(string $queue): string
    {
        return $queue.'.delay';
    }

    private function resolveQueueName(?string $queue): string
    {
        return $queue ?? $this->defaultQueueName;
    }

    public function getChannel(): AMQPChannel
    {
        return $this->channel;
    }

    public function retryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }

    public function __destruct()
    {
        try {
            $this->channel->close();
            $this->connection->close();
        } catch (Throwable) {
            // Best-effort cleanup only — a broker connection already lost
            // (worker shutting down, process killed) must never throw from
            // a destructor.
        }
    }
}
