<?php

namespace Tests\Support;

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

/**
 * Creates one row in every tenant-scoped table
 * (config('tenancy.tenant_scoped_tables')) for a given tenant, honoring
 * each table's real foreign keys, so the tenant-isolation suite gets
 * genuine coverage of every manifest table rather than a hand-picked
 * subset.
 *
 * Must be called from inside a TenantContext::run() block for $tenant's own
 * id: every tenant-scoped table's INSERT is itself subject to its RLS
 * policy's WITH CHECK clause (Postgres defaults WITH CHECK to the policy's
 * USING expression when none is given separately), so an insert issued
 * with no matching tenant context set is rejected exactly like a read
 * would be — this is deliberate, not a limitation of this helper.
 */
final class TenantFixture
{
    /** @return array<string, string> table name => created row id, in config('tenancy.tenant_scoped_tables') order */
    public static function seedOneRowPerTable(Tenant $tenant): array
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $staff = Staff::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id]);
        $service = Service::factory()->create(['tenant_id' => $tenant->id]);
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);

        $workingHour = StaffWorkingHour::factory()->create([
            'tenant_id' => $tenant->id,
            'staff_id' => $staff->id,
        ]);

        $exception = AvailabilityException::factory()->create([
            'tenant_id' => $tenant->id,
            'staff_id' => $staff->id,
        ]);

        $appointment = Appointment::factory()->create([
            'tenant_id' => $tenant->id,
            'staff_id' => $staff->id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
        ]);

        $payment = Payment::factory()->create([
            'tenant_id' => $tenant->id,
            'appointment_id' => $appointment->id,
        ]);

        $refund = Refund::factory()->create([
            'tenant_id' => $tenant->id,
            'payment_id' => $payment->id,
        ]);

        $mandate = PaymentMandate::factory()->create([
            'tenant_id' => $tenant->id,
            'appointment_id' => $appointment->id,
        ]);

        $event = BookingEvent::factory()->create([
            'tenant_id' => $tenant->id,
            'appointment_id' => $appointment->id,
        ]);

        $notification = NotificationDelivery::factory()->create([
            'tenant_id' => $tenant->id,
            'appointment_id' => $appointment->id,
        ]);

        return [
            'users' => $user->id,
            'staff' => $staff->id,
            'services' => $service->id,
            'customers' => $customer->id,
            'staff_working_hours' => $workingHour->id,
            'availability_exceptions' => $exception->id,
            'appointments' => $appointment->id,
            'payments' => $payment->id,
            'refunds' => $refund->id,
            'payment_mandates' => $mandate->id,
            'booking_events' => $event->id,
            'notification_deliveries' => $notification->id,
        ];
    }
}
