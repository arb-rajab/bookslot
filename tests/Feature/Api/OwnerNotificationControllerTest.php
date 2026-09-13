<?php

use App\Models\NotificationDelivery;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;
use Tests\Support\BookingFixture;

use function Pest\Laravel\getJson;

/**
 * GET /api/owner/notifications (D-0051) — the reminder delivery log/status
 * view. D-0050 (Session 19) built real sending with no read surface; this
 * closes that gap.
 */
function ownerNotificationTenant(): Tenant
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

function loginAsNotificationOwner(Tenant $tenant): string
{
    disableConsoleCsrfBypass();

    [$xsrf, $login] = loginAndCaptureXsrf("/api/tenants/{$tenant->slug}/login", [
        'email' => 'owner@example.test',
        'password' => 'correct-password',
    ]);
    $login->assertOk();

    return $xsrf;
}

test('an owner sees their tenant\'s reminder deliveries, newest first, never another tenant\'s', function () {
    $tenant = ownerNotificationTenant();
    $appointment = BookingFixture::appointmentFor($tenant);

    TenantContext::run($tenant->id, function () use ($tenant, $appointment) {
        NotificationDelivery::factory()->create([
            'tenant_id' => $tenant->id, 'appointment_id' => $appointment->id,
            'purpose' => 'reminder_7d', 'status' => 'sent', 'scheduled_for' => now()->subDays(2),
        ]);
        NotificationDelivery::factory()->create([
            'tenant_id' => $tenant->id, 'appointment_id' => $appointment->id,
            'purpose' => 'reminder_24h', 'status' => 'failed', 'scheduled_for' => now()->subHours(1),
        ]);
    });

    $otherTenant = Tenant::factory()->create();
    $otherAppointment = BookingFixture::appointmentFor($otherTenant);
    TenantContext::run($otherTenant->id, fn () => NotificationDelivery::factory()->create([
        'tenant_id' => $otherTenant->id, 'appointment_id' => $otherAppointment->id,
    ]));

    $xsrf = loginAsNotificationOwner($tenant);

    $response = getJson('/api/owner/notifications', ['Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf]);
    $response->assertOk();

    $notifications = collect($response->json('notifications'));
    expect($notifications)->toHaveCount(2);
    expect($notifications->pluck('appointment_id')->unique()->first())->toBe($appointment->id);
    expect($notifications->first()['purpose'])->toBe('reminder_24h');
});

test('an owner filters reminder deliveries by status', function () {
    $tenant = ownerNotificationTenant();
    $appointment = BookingFixture::appointmentFor($tenant);

    TenantContext::run($tenant->id, function () use ($tenant, $appointment) {
        NotificationDelivery::factory()->create(['tenant_id' => $tenant->id, 'appointment_id' => $appointment->id, 'purpose' => 'reminder_7d', 'status' => 'sent']);
        NotificationDelivery::factory()->create(['tenant_id' => $tenant->id, 'appointment_id' => $appointment->id, 'purpose' => 'reminder_24h', 'status' => 'failed']);
    });

    $xsrf = loginAsNotificationOwner($tenant);

    $response = getJson('/api/owner/notifications?status=failed', ['Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf]);
    $response->assertOk();

    $statuses = collect($response->json('notifications'))->pluck('status')->unique();
    expect($statuses->all())->toBe(['failed']);
});

test('an unauthenticated request to list reminder deliveries is rejected', function () {
    getJson('/api/owner/notifications')->assertStatus(401);
});
