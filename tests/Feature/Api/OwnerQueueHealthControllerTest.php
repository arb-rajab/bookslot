<?php

use App\Models\NotificationDelivery;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;
use Tests\Support\BookingFixture;

use function Pest\Laravel\getJson;

/**
 * GET /api/owner/queue-health (D-0051) — basic queue/job health visibility.
 * The fast gate never has a real RabbitMQ management API to reach (see
 * .env.testing — no RABBITMQ_MANAGEMENT_URL override), so this suite
 * proves the broker section degrades to `available: false` rather than a
 * 500, and that the DB-derived sections (real counts this codebase
 * persists) are correct and tenant-scoped.
 */
function ownerQueueHealthTenant(): Tenant
{
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function () use ($tenant) {
        User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'owner',
            'email' => 'owner@example.test',
            'password_hash' => Hash::make('correct-password'),
        ]);
    });

    return $tenant;
}

function loginAsQueueHealthOwner(Tenant $tenant): string
{
    disableConsoleCsrfBypass();

    [$xsrf, $login] = loginAndCaptureXsrf("/api/tenants/{$tenant->slug}/login", [
        'email' => 'owner@example.test',
        'password' => 'correct-password',
    ]);
    $login->assertOk();

    return $xsrf;
}

test('queue health reports real reminder-delivery counts and degrades the broker section without a 500', function () {
    $tenant = ownerQueueHealthTenant();
    $appointment = BookingFixture::appointmentFor($tenant);
    BookingFixture::appointmentFor($tenant, ['status' => 'pending_payment']);

    TenantContext::run($tenant->id, function () use ($tenant, $appointment) {
        NotificationDelivery::factory()->create(['tenant_id' => $tenant->id, 'appointment_id' => $appointment->id, 'purpose' => 'reminder_7d', 'status' => 'sent']);
        NotificationDelivery::factory()->create(['tenant_id' => $tenant->id, 'appointment_id' => $appointment->id, 'purpose' => 'reminder_24h', 'status' => 'failed']);
    });

    $xsrf = loginAsQueueHealthOwner($tenant);

    $response = getJson('/api/owner/queue-health', ['Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf]);

    $response->assertOk();
    $response->assertJsonStructure(['broker', 'reminders' => ['sent', 'failed', 'scheduled', 'cancelled'], 'pending_payment_appointments']);
    expect($response->json('reminders.sent'))->toBe(1);
    expect($response->json('reminders.failed'))->toBe(1);
    expect($response->json('pending_payment_appointments'))->toBe(1);
    expect($response->json('broker.available'))->toBeFalse();
});

test('an unauthenticated request to view queue health is rejected', function () {
    getJson('/api/owner/queue-health')->assertStatus(401);
});
