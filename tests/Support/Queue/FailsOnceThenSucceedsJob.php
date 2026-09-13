<?php

namespace Tests\Support\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * Proves RabbitMqJob::release()/RabbitMqQueue::republishForRetry() actually
 * work against a real broker: attempt 1 always throws (causing
 * CallQueuedHandler to call release(0) since $tries=3 is not yet
 * exhausted); attempt 2 (attempts() === 2, read from the real
 * x-bookslot-attempt message header round-tripped through RabbitMQ) writes
 * the marker file. A `--once` worker run only ever processes one message
 * per invocation, so RabbitMqQueueIntegrationTest runs `queue:work --once`
 * twice to observe both attempts.
 */
class FailsOnceThenSucceedsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [0];

    public function __construct(private readonly string $filePath) {}

    public function handle(): void
    {
        if ($this->attempts() < 2) {
            throw new RuntimeException('Simulated first-attempt failure for RabbitMqQueueIntegrationTest.');
        }

        file_put_contents($this->filePath, 'succeeded on attempt '.$this->attempts());
    }
}
