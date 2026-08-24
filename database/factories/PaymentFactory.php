<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\Payment;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'appointment_id' => Appointment::factory(),
            'type' => 'deposit',
            'stripe_payment_intent_id' => 'pi_'.fake()->unique()->bothify('####################'),
            'amount' => 2000,
            'currency' => 'usd',
            'status' => 'succeeded',
        ];
    }
}
