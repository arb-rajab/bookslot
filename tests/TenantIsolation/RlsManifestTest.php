<?php

use Illuminate\Support\Facades\DB;

/**
 * The highest-value test in the suite (07-testing-strategy.md): it must
 * catch a new tenant-scoped table shipped without an RLS policy — table
 * thirteen added in six months — not just the tables that exist today. Both
 * checks are parameterized over the live schema and
 * config('tenancy.tenant_scoped_tables'), never a hand-maintained per-table
 * list.
 */
test('every table with a tenant_id column is declared in the tenancy manifest', function () {
    $manifestTables = collect(config('tenancy.tenant_scoped_tables'))->sort()->values()->all();

    $actualTenantScopedTables = collect(DB::select(<<<'SQL'
        select table_name
        from information_schema.columns
        where table_schema = 'public'
          and column_name = 'tenant_id'
        order by table_name
    SQL))->pluck('table_name')->sort()->values()->all();

    expect($actualTenantScopedTables)->toBe($manifestTables);
});

test('every manifest table has row level security enabled, forced, and a tenant_isolation policy', function () {
    foreach (config('tenancy.tenant_scoped_tables') as $table) {
        $row = DB::selectOne(
            "select relrowsecurity, relforcerowsecurity from pg_class where relname = ? and relnamespace = 'public'::regnamespace",
            [$table]
        );

        expect($row)->not->toBeNull("Table [{$table}] does not exist.");
        expect((bool) $row->relrowsecurity)->toBeTrue("Table [{$table}] does not have row level security enabled.");
        expect((bool) $row->relforcerowsecurity)->toBeTrue("Table [{$table}] does not have row level security forced.");

        $policyCount = DB::selectOne(
            "select count(*)::int as count from pg_policies where schemaname = 'public' and tablename = ? and policyname = 'tenant_isolation'",
            [$table]
        );

        expect($policyCount->count)->toBe(1, "Table [{$table}] is missing its tenant_isolation policy.");
    }
});

test('tenants and stripe_webhook_events are deliberately outside the manifest and outside RLS', function () {
    // tenants is the tenancy boundary itself, not inside it; a webhook may
    // arrive before its tenant is known. Asserted explicitly so a future
    // change that accidentally adds either to the manifest — or removes
    // this exclusion silently — shows up here.
    $manifestTables = config('tenancy.tenant_scoped_tables');

    expect($manifestTables)->not->toContain('tenants');
    expect($manifestTables)->not->toContain('stripe_webhook_events');

    foreach (['tenants', 'stripe_webhook_events'] as $table) {
        $row = DB::selectOne(
            "select relrowsecurity from pg_class where relname = ? and relnamespace = 'public'::regnamespace",
            [$table]
        );

        expect((bool) $row->relrowsecurity)->toBeFalse("Table [{$table}] unexpectedly has row level security enabled.");
    }
});
