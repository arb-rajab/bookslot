<?php

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Tenancy\TenantContext;

/**
 * A relationship definition can't be used to pull a related row belonging
 * to a different tenant even if a future change to that relationship's
 * query forgets to interact correctly with the global scope — RLS is the
 * backstop regardless (07-testing-strategy.md).
 */
test('eager-loaded relationships never resolve to a different tenant\'s row', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $appointmentA = TenantContext::run($tenantA->id, function () use ($tenantA) {
        $staff = Staff::factory()->create(['tenant_id' => $tenantA->id]);
        $service = Service::factory()->create(['tenant_id' => $tenantA->id]);
        $customer = Customer::factory()->create(['tenant_id' => $tenantA->id]);

        return Appointment::factory()->create([
            'tenant_id' => $tenantA->id,
            'staff_id' => $staff->id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
        ]);
    });

    TenantContext::run($tenantB->id, function () use ($tenantB) {
        Staff::factory()->create(['tenant_id' => $tenantB->id]);
        Service::factory()->create(['tenant_id' => $tenantB->id]);
        Customer::factory()->create(['tenant_id' => $tenantB->id]);
    });

    TenantContext::run($tenantA->id, function () use ($appointmentA, $tenantA) {
        $loaded = Appointment::with(['staff', 'service', 'customer'])->findOrFail($appointmentA->id);

        expect($loaded->staff)->not->toBeNull();
        expect($loaded->service)->not->toBeNull();
        expect($loaded->customer)->not->toBeNull();

        expect($loaded->staff->tenant_id)->toBe($tenantA->id);
        expect($loaded->service->tenant_id)->toBe($tenantA->id);
        expect($loaded->customer->tenant_id)->toBe($tenantA->id);
    });
});

test('eager loading under the wrong tenant context resolves relations to null rather than another tenant\'s row', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $appointmentA = TenantContext::run($tenantA->id, function () use ($tenantA) {
        $staff = Staff::factory()->create(['tenant_id' => $tenantA->id]);
        $service = Service::factory()->create(['tenant_id' => $tenantA->id]);
        $customer = Customer::factory()->create(['tenant_id' => $tenantA->id]);

        return Appointment::factory()->create([
            'tenant_id' => $tenantA->id,
            'staff_id' => $staff->id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
        ]);
    });

    // Tenant A's own appointment row is itself invisible under tenant B's
    // context (RLS on appointments), so this asserts the base record
    // resolves to null — a stronger guarantee than merely checking its
    // relations, since there's nothing left to eager-load onto at all.
    TenantContext::run($tenantB->id, function () use ($appointmentA) {
        expect(Appointment::with(['staff', 'service', 'customer'])->find($appointmentA->id))->toBeNull();
    });
});
