<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffWorkingHour;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * One real, bookable demo tenant — needed for the first time this session,
 * since the public booking page (frontend/) has no owner dashboard to
 * create services/staff through yet, and manually exercising the real
 * booking flow needs real rows to book against. Refuses to run outside
 * local/testing, same defensive posture as the rest of this project's
 * environment-sensitive code (e.g. D-0020's migrator-credential handling).
 *
 * Session 17 addition: a real owner login (Session 16's seeder had no user
 * at all — the owner dashboard this session builds had no credentials to
 * log into against the seeded tenant) plus a small set of real appointments
 * spanning past/upcoming and confirmed/pending_payment, so the dashboard is
 * demoable immediately after `php artisan db:seed` rather than requiring a
 * manual booking first.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command->error('DatabaseSeeder only seeds local/testing environments.');

            return;
        }

        $tenant = Tenant::query()->firstOrCreate(
            ['slug' => 'demo-studio'],
            [
                'name' => 'Demo Tattoo Studio',
                'timezone' => 'America/Toronto',
                'currency' => 'usd',
                'stripe_onboarding_status' => 'not_started',
            ],
        );

        TenantContext::run($tenant->id, function () use ($tenant) {
            $staff = Staff::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'display_name' => 'Jamie Artist'],
                ['is_active' => true],
            );

            $service = Service::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'name' => 'Small Tattoo Session'],
                [
                    'duration_minutes' => 60,
                    'price_amount' => 20000,
                    'currency' => 'usd',
                    'deposit_type' => 'fixed',
                    'deposit_fixed_amount' => 5000,
                    'deposit_percentage_bps' => null,
                    'buffer_before_minutes' => 0,
                    'buffer_after_minutes' => 15,
                    'is_active' => true,
                ],
            );

            foreach (range(0, 6) as $dayOfWeek) {
                StaffWorkingHour::query()->firstOrCreate(
                    ['tenant_id' => $tenant->id, 'staff_id' => $staff->id, 'day_of_week' => $dayOfWeek],
                    ['start_time' => '09:00:00', 'end_time' => '17:00:00'],
                );
            }

            User::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'email' => 'owner@demo-studio.test'],
                [
                    'role' => 'owner',
                    'name' => 'Demo Owner',
                    'password_hash' => Hash::make('password'),
                    'email_verified_at' => now(),
                ],
            );

            $this->seedDemoAppointments($tenant, $staff, $service);
        });

        $this->command->info("Seeded demo tenant: /tenants/{$tenant->slug}");
        $this->command->info('Owner login: owner@demo-studio.test / password');
    }

    /**
     * Skipped entirely if this tenant already has any appointment — keeps
     * re-running `db:seed` idempotent without needing a per-row unique key
     * to `firstOrCreate` against (D-0007/D-0008's exclusion constraint has
     * no such natural key, and picking arbitrary fixed dates would risk a
     * real overlap failure on a second seed run).
     */
    private function seedDemoAppointments(Tenant $tenant, Staff $staff, Service $service): void
    {
        if (Appointment::query()->where('tenant_id', $tenant->id)->exists()) {
            return;
        }

        $alex = Customer::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Alex Rivera',
            'email' => 'alex@example.test',
        ]);

        $sam = Customer::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Sam Lee',
            'email' => 'sam@example.test',
        ]);

        $jordan = Customer::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Jordan Nguyen',
            'email' => 'jordan@example.test',
        ]);

        // Past, confirmed — ready for the owner to actually mark
        // attended/no-show through the real dashboard this session builds.
        $this->createDemoAppointment($tenant, $staff, $service, $alex, CarbonImmutable::now()->subDay(), 'confirmed', 'succeeded');

        // Upcoming, confirmed.
        $this->createDemoAppointment($tenant, $staff, $service, $sam, CarbonImmutable::now()->addWeek(), 'confirmed', 'succeeded');

        // Upcoming, still pending_payment — demonstrates the correctly-
        // blocked transition (D-0042): this one cannot be marked
        // attended/no-show yet.
        $this->createDemoAppointment($tenant, $staff, $service, $jordan, CarbonImmutable::now()->addDays(3), 'pending_payment', 'requires_action');
    }

    private function createDemoAppointment(
        Tenant $tenant,
        Staff $staff,
        Service $service,
        Customer $customer,
        CarbonImmutable $startsAt,
        string $status,
        string $depositStatus,
    ): void {
        $endsAt = $startsAt->addMinutes($service->duration_minutes);

        $appointment = Appointment::query()->create([
            'tenant_id' => $tenant->id,
            'staff_id' => $staff->id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'appointment_range' => sprintf('[%s,%s)', $startsAt->toIso8601String(), $endsAt->toIso8601String()),
            'buffer_before_minutes' => $service->buffer_before_minutes,
            'buffer_after_minutes' => $service->buffer_after_minutes,
            'status' => $status,
        ]);

        Payment::query()->create([
            'tenant_id' => $tenant->id,
            'appointment_id' => $appointment->id,
            'type' => 'deposit',
            'stripe_payment_intent_id' => 'pi_demo_'.$appointment->id,
            'amount' => $service->deposit_fixed_amount,
            'currency' => $service->currency,
            'status' => $depositStatus,
        ]);
    }
}
