<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\PaymentMandate;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentMandate>
 */
class PaymentMandateFactory extends Factory
{
    protected $model = PaymentMandate::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'appointment_id' => Appointment::factory(),
            'mandate_text' => 'By booking, you authorize a future off-session charge for the remaining balance.',
            'mandate_template_version' => 'v1',
            'balance_amount_disclosed' => 8000,
            'accepted_at' => now(),
            'accepted_ip' => '127.0.0.1',
            'accepted_user_agent' => 'Pest Test Runner',
            'stripe_payment_intent_id' => 'pi_'.fake()->unique()->bothify('####################'),
            'stripe_payment_method_id' => 'pm_'.fake()->unique()->bothify('####################'),
        ];
    }
}
