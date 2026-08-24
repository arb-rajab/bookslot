<?php

namespace App\Jobs\Middleware;

use App\Tenancy\TenantContext;
use Closure;

/**
 * Job middleware that wraps the job's handle() call in TenantContext::run()
 * for the tenant id serialized on the job itself (see
 * App\Jobs\TenantScopedJob). Because middleware() is invoked fresh on every
 * execution attempt — including retries — the job's own tenant_id is
 * re-supplied and the GUC re-set every time, so a retry on a worker that
 * most recently ran a different tenant's job can never inherit that
 * tenant's context (D-0009).
 */
class SetsTenantContext
{
    public function __construct(private readonly string $tenantId) {}

    public function handle(mixed $job, Closure $next): void
    {
        TenantContext::run($this->tenantId, fn () => $next($job));
    }
}
