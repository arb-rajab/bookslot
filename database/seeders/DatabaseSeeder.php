<?php

namespace Database\Seeders;

use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffWorkingHour;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * One real, bookable demo tenant — needed for the first time this session,
 * since the public booking page (frontend/) has no owner dashboard to
 * create services/staff through yet, and manually exercising the real
 * booking flow needs real rows to book against. Refuses to run outside
 * local/testing, same defensive posture as the rest of this project's
 * environment-sensitive code (e.g. D-0020's migrator-credential handling).
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

            Service::query()->firstOrCreate(
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
        });

        $this->command->info("Seeded demo tenant: /tenants/{$tenant->slug}");
    }
}
