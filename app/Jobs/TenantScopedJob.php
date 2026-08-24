<?php

namespace App\Jobs;

use App\Jobs\Middleware\SetsTenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use InvalidArgumentException;

/**
 * D-0009: every tenant-touching queued job extends this base. tenant_id is
 * a required constructor argument — a job that omits it cannot be
 * constructed, let alone dispatched, so "dispatched without tenant
 * context" fails at dispatch time rather than running silently with no
 * context set. The job middleware (SetsTenantContext) opens the
 * transaction and sets the GUC from this job's own serialized tenant_id on
 * every execution attempt, including retries.
 *
 * Tenant-less platform housekeeping (e.g. purging stripe_webhook_events)
 * uses App\Jobs\PlatformJob instead, which sets no tenant GUC at all.
 */
abstract class TenantScopedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected readonly string $tenantId;

    public function __construct(string $tenantId)
    {
        if ($tenantId === '') {
            throw new InvalidArgumentException(
                static::class.' requires a non-empty tenant_id — a tenant-scoped job may never be dispatched without one.'
            );
        }

        $this->tenantId = $tenantId;
    }

    public function middleware(): array
    {
        return [new SetsTenantContext($this->tenantId)];
    }
}
