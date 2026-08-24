<?php

namespace Database\Factories;

use App\Models\AvailabilityException;
use App\Models\Staff;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AvailabilityException>
 */
class AvailabilityExceptionFactory extends Factory
{
    protected $model = AvailabilityException::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'staff_id' => Staff::factory(),
            'date' => now()->addDays(fake()->numberBetween(1, 60))->toDateString(),
            'is_available' => false,
            'reason' => 'Holiday',
        ];
    }
}
