<?php

namespace Database\Factories;

use App\Models\Service;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->words(2, true),
            'duration_minutes' => 60,
            'price_amount' => 10000,
            'currency' => 'usd',
            'deposit_type' => 'fixed',
            'deposit_fixed_amount' => 2000,
            'deposit_percentage_bps' => null,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 15,
            'is_active' => true,
        ];
    }
}
