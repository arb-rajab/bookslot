<?php

use App\Models\Appointment;
use App\Models\AvailabilityException;
use App\Models\BookingEvent;
use App\Models\Customer;
use App\Models\NotificationDelivery;
use App\Models\Payment;
use App\Models\PaymentMandate;
use App\Models\Refund;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffWorkingHour;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Tests\Support\TenantFixture;

/**
 * "Cross-tenant ID guessing" per 07-testing-strategy.md, at the model
 * layer rather than via an HTTP endpoint — no controllers exist this
 * session (see the session's hard scope boundary). This is the direct
 * replacement: given tenant A's real, valid context, attempt to fetch a
 * real row belonging to tenant B by its actual (non-guessed, known-valid)
 * id, exactly as an authorization bug or a forgotten scope would attempt.
 */
test('every manifest table refuses to resolve another tenant\'s row by its real id, via Eloquent', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    TenantContext::run($tenantA->id, fn () => TenantFixture::seedOneRowPerTable($tenantA));
    $rowsB = TenantContext::run($tenantB->id, fn () => TenantFixture::seedOneRowPerTable($tenantB));

    $modelsByTable = [
        'users' => User::class,
        'staff' => Staff::class,
        'services' => Service::class,
        'customers' => Customer::class,
        'staff_working_hours' => StaffWorkingHour::class,
        'availability_exceptions' => AvailabilityException::class,
        'appointments' => Appointment::class,
        'payments' => Payment::class,
        'refunds' => Refund::class,
        'payment_mandates' => PaymentMandate::class,
        'booking_events' => BookingEvent::class,
        'notification_deliveries' => NotificationDelivery::class,
    ];

    TenantContext::run($tenantA->id, function () use ($modelsByTable, $rowsB) {
        foreach ($modelsByTable as $table => $modelClass) {
            expect($modelClass::find($rowsB[$table]))
                ->toBeNull("Tenant A resolved tenant B's real [{$table}] row by id via {$modelClass}::find().");
        }
    });
});

test('cross-tenant access via a real relationship id (not just the primary key) also resolves to nothing', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $staffB = TenantContext::run($tenantB->id, fn () => Staff::factory()->create(['tenant_id' => $tenantB->id]));

    TenantContext::run($tenantA->id, function () use ($tenantA, $staffB) {
        $serviceA = Service::factory()->create(['tenant_id' => $tenantA->id]);
        $customerA = Customer::factory()->create(['tenant_id' => $tenantA->id]);

        // A real staff id, belonging to a different tenant, used as a
        // foreign key while tenant A's own context is active. The FK
        // constraint itself doesn't check tenant_id equality across
        // tables — RLS's own read-back is what must catch this at the
        // point the appointment (and its relation) are ever queried again.
        $appointment = Appointment::factory()->create([
            'tenant_id' => $tenantA->id,
            'staff_id' => $staffB->id,
            'service_id' => $serviceA->id,
            'customer_id' => $customerA->id,
        ]);

        expect($appointment->fresh()->staff)->toBeNull();
    });
});
