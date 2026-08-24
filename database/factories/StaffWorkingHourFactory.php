<?php

namespace Database\Factories;

use App\Models\Staff;
use App\Models\StaffWorkingHour;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StaffWorkingHour>
 */
class StaffWorkingHourFactory extends Factory
{
    protected $model = StaffWorkingHour::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'staff_id' => Staff::factory(),
            'day_of_week' => fake()->numberBetween(0, 6),
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
        ];
    }
}
