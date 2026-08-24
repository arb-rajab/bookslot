<?php

namespace Tests\Support;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Tenancy\TenantContext;

/**
 * No booking-creation endpoint exists this session (see this session's
 * report/handoff) — Feature tests exercising the manage-booking and
 * confirm-payment endpoints need a real appointment (plus its staff,
 * service, and customer) to mint a token against, seeded directly rather
 * than through an HTTP request.
 */
final class BookingFixture
{
    /** @param array<string, mixed> $attributes */
    public static function appointmentFor(Tenant $tenant, array $attributes = []): Appointment
    {
        return TenantContext::run($tenant->id, function () use ($tenant, $attributes) {
            $staff = Staff::factory()->create(['tenant_id' => $tenant->id]);
            $service = Service::factory()->create(['tenant_id' => $tenant->id]);
            $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);

            return Appointment::factory()->create(array_merge([
                'tenant_id' => $tenant->id,
                'staff_id' => $staff->id,
                'service_id' => $service->id,
                'customer_id' => $customer->id,
            ], $attributes));
        });
    }

    /**
     * A Feature test's HTTP request tears down its own tenant context when
     * it finishes (SetTenantContext restores the prior, null, CurrentTenant
     * on exit) — a bare Appointment::find() after the response comes back
     * would be fail-closed by the app-layer scope and always resolve to
     * null regardless of what actually happened. Assertions on
     * post-request database state read through TenantContext::run() for
     * that reason.
     */
    public static function assertStatus(Tenant $tenant, string $appointmentId, string $expected): void
    {
        TenantContext::run($tenant->id, function () use ($appointmentId, $expected) {
            expect(Appointment::find($appointmentId)->status)->toBe($expected);
        });
    }
}
