<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\NotificationDelivery;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationDelivery>
 */
class NotificationDeliveryFactory extends Factory
{
    protected $model = NotificationDelivery::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'appointment_id' => Appointment::factory(),
            'purpose' => 'reminder_24h',
            'channel' => 'email',
            'scheduled_for' => now()->addDay(),
            'status' => 'scheduled',
        ];
    }
}
