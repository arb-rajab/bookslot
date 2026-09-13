<?php

namespace Tests\Support\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * D-0047 (docs/project-memory/09-decision-log.md): a plain (not
 * TenantScopedJob) job used only by RabbitMqQueueIntegrationTest —
 * deliberately not tenant-scoped, since this test proves the raw AMQP
 * transport (real publish, real delayed delivery via the TTL+DLX pattern),
 * a concern orthogonal to D-0009's tenant-context middleware, which
 * TenantScopedJob's own test coverage (QueueJobTenantContextTest) already
 * proves independently. Writes to a real file rather than in-process
 * static state because the consuming `php artisan queue:work` process this
 * test spawns is a genuinely separate OS process — the same reason
 * tests/Support/concurrency's probe scripts exist.
 */
class WritesMarkerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly string $filePath, private readonly string $marker) {}

    public function handle(): void
    {
        file_put_contents($this->filePath, $this->marker);
    }
}
