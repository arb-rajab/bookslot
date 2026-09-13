<?php

namespace App\Queue\RabbitMq;

use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * See RabbitMqQueue's class docblock (D-0047) for the delay/retry design
 * this class's release()/attempts() implement. Wraps one `basic_get`ed
 * AMQPMessage — delete() and release() are the only two places a message
 * actually leaves the queue it was fetched from (an ack either way), so a
 * message this class never explicitly acks or nacks stays invisible-but-
 * unconsumed until this channel closes, per normal AMQP consumer semantics.
 */
class RabbitMqJob extends Job implements JobContract
{
    public function __construct(
        Container $container,
        private readonly RabbitMqQueue $rabbitMqQueue,
        private readonly AMQPChannel $channel,
        string $connectionName,
        string $queue,
        private readonly AMQPMessage $message,
    ) {
        $this->container = $container;
        $this->connectionName = $connectionName;
        $this->queue = $queue;
    }

    public function getJobId(): string
    {
        return $this->message->has('message_id') ? $this->message->get('message_id') : (string) $this->message->getDeliveryTag();
    }

    public function getRawBody(): string
    {
        return $this->message->getBody();
    }

    public function attempts(): int
    {
        $headers = $this->message->has('application_headers') ? $this->message->get('application_headers') : null;

        if ($headers === null) {
            return 1;
        }

        /** @var array<string, mixed> $native */
        $native = $headers->getNativeData();

        return ((int) ($native['x-bookslot-attempt'] ?? 0)) + 1;
    }

    public function delete(): void
    {
        parent::delete();

        $this->channel->basic_ack($this->message->getDeliveryTag());
    }

    public function release($delay = 0): void
    {
        parent::release($delay);

        // The retried attempt is a brand-new message (RabbitMqQueue::
        // republishForRetry) carrying the incremented attempt header — the
        // original delivery is ack'd here, never nack'd-with-requeue,
        // because a plain requeue would put it back at attempt count 1
        // with no way for attempts()/maxTries() to ever terminate a
        // failing job.
        $this->rabbitMqQueue->republishForRetry(
            $this->getRawBody(),
            $this->queue,
            $this->attempts(),
            $this->delaySeconds($delay),
        );

        $this->channel->basic_ack($this->message->getDeliveryTag());
    }

    private function delaySeconds(mixed $delay): int
    {
        if ($delay instanceof \DateTimeInterface) {
            return max($delay->getTimestamp() - time(), 0);
        }

        if ($delay instanceof \DateInterval) {
            return max((new \DateTimeImmutable)->add($delay)->getTimestamp() - time(), 0);
        }

        return max((int) $delay, 0);
    }
}
