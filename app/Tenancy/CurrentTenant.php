<?php

namespace App\Tenancy;

/**
 * The app-layer half of D-0005's two-layer tenant isolation (the other half
 * is the Postgres RLS policy each tenant-scoped table carries). Holds the
 * tenant id for the duration of whatever TenantContext::run() call is
 * currently active, so Eloquent's global tenant scope has something to
 * filter on without threading a tenant id through every query manually.
 *
 * This is process/request-local, in-memory state — it carries no
 * durability of its own. The real, fail-closed boundary is the database
 * GUC TenantContext sets alongside it; this class exists only so the
 * application-layer scope (the first of D-0005's two layers) has a value
 * to read.
 */
final class CurrentTenant
{
    private static ?string $id = null;

    public static function id(): ?string
    {
        return self::$id;
    }

    public static function set(?string $tenantId): void
    {
        self::$id = $tenantId;
    }

    public static function clear(): void
    {
        self::$id = null;
    }
}
