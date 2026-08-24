<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    protected $model = Appointment::class;

    public function definition(): array
    {
        // A unique, far-future slot index per row keeps distinct factory
        // calls from colliding against the tenant+staff exclusion
        // constraint (D-0007/D-0008) without this factory needing to know
        // anything about real availability logic.
        $slot = fake()->unique()->numberBetween(1, 100000);

        $start = now()->addHours($slot);
        $end = (clone $start)->addHour();

        return [
            'tenant_id' => Tenant::factory(),
            'staff_id' => Staff::factory(),
            'service_id' => Service::factory(),
            'customer_id' => Customer::factory(),
            'appointment_range' => sprintf('[%s,%s)', $start->toIso8601String(), $end->toIso8601String()),
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 15,
            'status' => 'confirmed',
        ];
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'cancelled',
            'cancelled_by' => 'customer',
            'cancelled_reason' => 'Test cancellation',
            'cancelled_at' => now(),
        ]);
    }
}
