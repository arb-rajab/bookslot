<?php

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Wraps Laravel's RefreshDatabase so its one-time migrate:fresh call runs
 * through the bookslot_migrator connection instead of the application's own
 * runtime connection. bookslot_app deliberately doesn't own the tables and
 * has no CREATE/DROP privilege on them (D-0009/D-0020 in
 * docs/project-memory/09-decision-log.md) — RefreshDatabase's default
 * migrateFreshUsing() would otherwise run migrate:fresh through bookslot_app
 * and fail with "must be owner of table."
 *
 * Only the one-time schema setup goes through the migrator; each test's own
 * transaction (RefreshDatabase's beginDatabaseTransaction()) still runs on
 * config('database.default') — the same bookslot_app connection production
 * code actually uses.
 */
trait RefreshesTenantDatabase
{
    use RefreshDatabase {
        migrateFreshUsing as protected baseMigrateFreshUsing;
    }

    protected function migrateFreshUsing(): array
    {
        return array_merge($this->baseMigrateFreshUsing(), [
            '--database' => 'pgsql_migrator',
        ]);
    }
}
