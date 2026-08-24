<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\Refund;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Refund>
 */
class RefundFactory extends Factory
{
    protected $model = Refund::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'payment_id' => Payment::factory(),
            'stripe_refund_id' => 're_'.fake()->unique()->bothify('####################'),
            'amount' => 1000,
            'status' => 'succeeded',
        ];
    }
}
