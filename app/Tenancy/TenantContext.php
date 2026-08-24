<?php

namespace App\Tenancy;

use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * D-0009 (docs/project-memory/09-decision-log.md): the tenant GUC is always
 * set via a parameterized set_config('app.current_tenant_id', ?, true) call
 * — never a string-interpolated SET LOCAL — as the first statement inside
 * an explicitly opened transaction. This is the ONLY mechanism used
 * anywhere in the system to establish tenant context; there is no
 * session-level fallback.
 *
 * D-0025 (execution-tested this session): a "local" set_config value resets
 * at the end of its transaction, but NOT to NULL — once a physical
 * connection has ever called set_config() for this GUC, Postgres treats it
 * as permanently defined for that connection's remaining lifetime, and
 * current_setting(..., true) returns an empty string, not NULL, whenever no
 * context is currently active. Under this project's chosen PgBouncer
 * transaction-mode pooling (connections deliberately reused across
 * requests), that's the normal state after a connection's first
 * tenant-scoped transaction, not a rare edge case. The RLS policies
 * themselves are hardened against this (NULLIF(..., '') before the ::uuid
 * cast — see the enable-RLS migration); this class's job is only to always
 * set a real value inside a real transaction, never to rely on an unset
 * GUC meaning anything in particular on its own.
 *
 * This is the smallest real mechanism the tenant-isolation suite drives
 * directly. A future session wires this into an actual HTTP request
 * lifecycle (via App\Http\Middleware\SetTenantContext) and real tenant
 * resolution (slug lookup, auth, signed token) — none of that exists yet,
 * per this session's scope.
 */
final class TenantContext
{
    /**
     * Run $callback inside a transaction with the tenant GUC set, on the
     * given (or default) connection. Fails closed: an empty/missing tenant
     * id is refused at call time rather than silently running with no
     * context set.
     */
    public static function run(string $tenantId, Closure $callback, ?string $connection = null): mixed
    {
        if ($tenantId === '') {
            throw new InvalidArgumentException('TenantContext::run() requires a non-empty tenant id.');
        }

        $db = DB::connection($connection);

        return $db->transaction(function () use ($db, $tenantId, $callback) {
            $db->statement('select set_config(?, ?, true)', [config('tenancy.guc'), $tenantId]);

            $previous = CurrentTenant::id();
            CurrentTenant::set($tenantId);

            try {
                return $callback();
            } finally {
                CurrentTenant::set($previous);
            }
        });
    }

    /**
     * Explicitly clears the tenant GUC on the given (or default) connection,
     * without opening a new transaction.
     *
     * Real request/job code never needs this — it's test infrastructure.
     * Two things make "no context set" hard to simulate faithfully inside a
     * single test: Pest's RefreshDatabase wraps a whole test in one outer
     * transaction, so a nested TenantContext::run() call only opens a
     * savepoint, and releasing a savepoint doesn't reset a transaction-local
     * setting the way a real COMMIT does; and per D-0025 (this class's own
     * docblock), even a real COMMIT doesn't return a previously-set custom
     * GUC to true NULL on the same connection, only to an empty string. A
     * test proving fail-closed behavior with real rows already present, on
     * a connection that has already set a context earlier in the same test,
     * must clear it explicitly rather than merely not calling run() again.
     */
    public static function clear(?string $connection = null): void
    {
        DB::connection($connection)->statement('select set_config(?, NULL, true)', [config('tenancy.guc')]);

        CurrentTenant::clear();
    }
}
