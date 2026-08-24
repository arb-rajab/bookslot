<?php

use App\Jobs\TenantScopedJob;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;

/**
 * A concrete TenantScopedJob subclass used only by this test file. Records,
 * for each run, the tenant GUC actually visible inside handle() — so a
 * "which tenant did this run observe" assertion can be made directly,
 * rather than inferred from side effects.
 */
class RecordsObservedTenantJob extends TenantScopedJob
{
    /** @var list<array{via_guc: string|null, via_current_tenant: string|null}> */
    private static array $observedTenantIds = [];

    public function handle(): void
    {
        $guc = DB::selectOne('select current_setting(?, true) as tenant_id', [config('tenancy.guc')]);

        self::$observedTenantIds[] = [
            'via_guc' => $guc->tenant_id,
            'via_current_tenant' => CurrentTenant::id(),
        ];
    }

    public static function reset(): void
    {
        self::$observedTenantIds = [];
    }

    /** @return list<array{via_guc: string|null, via_current_tenant: string|null}> */
    public static function observed(): array
    {
        return self::$observedTenantIds;
    }
}

test('a tenant-scoped job cannot be constructed without a tenant id', function () {
    expect(fn () => new RecordsObservedTenantJob(''))->toThrow(InvalidArgumentException::class);
});

test('dispatching a tenant-scoped job sets the tenant GUC for the duration of handle()', function () {
    RecordsObservedTenantJob::reset();

    $tenant = Tenant::factory()->create();

    RecordsObservedTenantJob::dispatchSync($tenant->id);

    $observed = RecordsObservedTenantJob::observed();

    expect($observed)->toHaveCount(1);
    expect($observed[0]['via_guc'])->toBe($tenant->id);
    expect($observed[0]['via_current_tenant'])->toBe($tenant->id);

    // The job middleware's finally-block restores the prior (null) value —
    // context does not leak past the job that set it.
    expect(CurrentTenant::id())->toBeNull();
});

test('a job retried after a different tenant\'s job ran on the same worker still observes its own tenant', function () {
    RecordsObservedTenantJob::reset();

    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    // Simulates: tenant A's job runs, then (as if on a reused worker
    // connection) tenant B's job runs, then tenant A's job retries. Per
    // D-0009, retries re-run handle() from the job's own serialized
    // tenant_id, so the GUC is re-set correctly regardless of whatever ran
    // on the worker in between — never inherited from B.
    RecordsObservedTenantJob::dispatchSync($tenantA->id);
    RecordsObservedTenantJob::dispatchSync($tenantB->id);
    RecordsObservedTenantJob::dispatchSync($tenantA->id); // the "retry"

    $observed = RecordsObservedTenantJob::observed();

    expect($observed)->toHaveCount(3);
    expect($observed[0]['via_guc'])->toBe($tenantA->id);
    expect($observed[1]['via_guc'])->toBe($tenantB->id);
    expect($observed[2]['via_guc'])->toBe($tenantA->id);
});
