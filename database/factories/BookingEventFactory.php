<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\BookingEvent;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookingEvent>
 */
class BookingEventFactory extends Factory
{
    protected $model = BookingEvent::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'appointment_id' => Appointment::factory(),
            'actor_type' => 'system',
            'event_type' => 'status_changed',
            'from_status' => 'pending_payment',
            'to_status' => 'confirmed',
        ];
    }
}
