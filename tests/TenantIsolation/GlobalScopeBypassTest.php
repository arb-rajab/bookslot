<?php

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\TenantFixture;

/**
 * The direct test of D-0005's core claim: RLS holds even when the
 * application-layer scope is bypassed entirely, deliberately or by a bug —
 * a raw DB::select, a DB::statement, and a query builder call that never
 * touch Eloquent or its global scope at all.
 *
 * Per 07-testing-strategy.md's factory convention: both tenants are
 * constructed inline, explicitly, in each test — never a shared "the test
 * tenant" fixture, which is exactly the kind of setup that could mask a
 * cross-tenant bug.
 */
test('RLS holds against raw queries and the query builder, bypassing Eloquent entirely', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $rowsA = TenantContext::run($tenantA->id, fn () => TenantFixture::seedOneRowPerTable($tenantA));
    $rowsB = TenantContext::run($tenantB->id, fn () => TenantFixture::seedOneRowPerTable($tenantB));

    TenantContext::run($tenantA->id, function () use ($rowsA, $rowsB) {
        foreach (config('tenancy.tenant_scoped_tables') as $table) {
            // Raw DB::select — never touches Eloquent or its global scope.
            $viaRawSelect = collect(DB::select("select id from {$table}"))->pluck('id');
            expect($viaRawSelect->contains($rowsA[$table]))
                ->toBeTrue("Tenant A's own row is missing from [{$table}] via DB::select.");
            expect($viaRawSelect->contains($rowsB[$table]))
                ->toBeFalse("Tenant B's row leaked into [{$table}] via DB::select.");

            // Query builder, no Eloquent model, no explicit tenant_id filter.
            $viaQueryBuilder = DB::table($table)->pluck('id');
            expect($viaQueryBuilder->contains($rowsA[$table]))
                ->toBeTrue("Tenant A's own row is missing from [{$table}] via the query builder.");
            expect($viaQueryBuilder->contains($rowsB[$table]))
                ->toBeFalse("Tenant B's row leaked into [{$table}] via the query builder.");
        }
    });
});

test('RLS rejects a raw DB::statement write claiming a different tenant than the active context', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    TenantContext::run($tenantA->id, function () use ($tenantB) {
        // WITH CHECK defaults to the policy's USING expression, so an
        // INSERT is gated exactly like a read — a raw, hand-written
        // statement claiming tenant B's id while context is A must be
        // rejected by Postgres itself, not merely by application code
        // remembering to set tenant_id correctly.
        $attempt = fn () => DB::statement(
            'insert into customers (id, tenant_id, name, email) values (gen_random_uuid(), ?, ?, ?)',
            [$tenantB->id, 'Cross-tenant attempt', 'cross-tenant@example.com']
        );

        expect($attempt)->toThrow(QueryException::class);
    });
});

test('RLS fails closed when no tenant context is set, even though matching rows exist', function () {
    $tenant = Tenant::factory()->create();

    $rows = TenantContext::run($tenant->id, fn () => TenantFixture::seedOneRowPerTable($tenant));

    // Explicitly clear rather than merely "not calling run() again" — see
    // TenantContext::clear()'s docblock for why this is necessary inside a
    // single wrapped test transaction.
    TenantContext::clear();

    foreach (config('tenancy.tenant_scoped_tables') as $table) {
        expect(DB::table($table)->where('id', $rows[$table])->exists())->toBeFalse(
            "[{$table}] returned a row with no tenant context set — RLS did not fail closed."
        );
    }
});
