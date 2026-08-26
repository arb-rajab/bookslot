<?php

use App\Models\Appointment;
use App\Models\AvailabilityException;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffWorkingHour;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

use function Pest\Laravel\getJson;

/**
 * GET /api/tenants/{slug}/availability (05-api-contracts.md endpoint 1,
 * 04-data-model.md's "derived, not materialized" slot algorithm) — never
 * built before this session. staff_working_hours + availability_exceptions
 * + existing appointments' buffered occupancy, projected onto a specific
 * future calendar date in the tenant's own IANA zone (never a fixed UTC
 * offset, per 04's DST-safety requirement).
 */
function availabilityTenant(): array
{
    $tenant = Tenant::factory()->create(['timezone' => 'America/Toronto']);

    return TenantContext::run($tenant->id, function () use ($tenant) {
        $staff = Staff::factory()->create(['tenant_id' => $tenant->id]);
        $service = Service::factory()->create([
            'tenant_id' => $tenant->id,
            'duration_minutes' => 60,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
        ]);

        return [$tenant, $staff, $service];
    });
}

/** A date far enough out that it's never "today" relative to the suite's clock, with a stable day_of_week. */
function aFutureDate(int $dayOfWeek): CarbonImmutable
{
    $date = CarbonImmutable::now('America/Toronto')->addDays(14)->startOfDay();

    while ($date->dayOfWeek !== $dayOfWeek) {
        $date = $date->addDay();
    }

    return $date;
}

test('computes bookable slots from working hours, honoring service duration and the slot increment', function () {
    [$tenant, $staff, $service] = availabilityTenant();
    $date = aFutureDate(2); // any fixed weekday works; the value itself is irrelevant to the assertion

    TenantContext::run($tenant->id, function () use ($tenant, $staff, $date) {
        StaffWorkingHour::factory()->create([
            'tenant_id' => $tenant->id,
            'staff_id' => $staff->id,
            'day_of_week' => $date->dayOfWeek,
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
        ]);
    });

    $response = getJson("/api/tenants/{$tenant->slug}/availability?".http_build_query([
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'from' => $date->toDateString(),
        'to' => $date->toDateString(),
    ]));

    $response->assertOk();
    $slots = $response->json('slots');

    // A 2-hour window, a 60-minute service, a 15-minute increment (config
    // default): 09:00, 09:15, 09:30, 09:45, 10:00 — the last slot whose end
    // (11:00) still fits inside the window.
    expect($slots)->toHaveCount(5);

    foreach ($slots as $slot) {
        expect($slot['staff_id'])->toBe($staff->id);
        $localStart = CarbonImmutable::parse($slot['starts_at'])->setTimezone('America/Toronto');
        $localEnd = CarbonImmutable::parse($slot['ends_at'])->setTimezone('America/Toronto');
        expect($localStart->toDateString())->toBe($date->toDateString());
        expect((int) $localStart->diffInMinutes($localEnd))->toBe(60);
    }

    $firstLocalStart = CarbonImmutable::parse($slots[0]['starts_at'])->setTimezone('America/Toronto');
    expect($firstLocalStart->format('H:i'))->toBe('09:00');
    $lastLocalStart = CarbonImmutable::parse($slots[4]['starts_at'])->setTimezone('America/Toronto');
    expect($lastLocalStart->format('H:i'))->toBe('10:00');
});

test('a conflicting existing appointment removes the overlapping slot, buffer included', function () {
    [$tenant, $staff, $service] = availabilityTenant();
    $date = aFutureDate(3);

    TenantContext::run($tenant->id, function () use ($tenant, $staff, $service, $date) {
        StaffWorkingHour::factory()->create([
            'tenant_id' => $tenant->id,
            'staff_id' => $staff->id,
            'day_of_week' => $date->dayOfWeek,
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
        ]);

        // Booked 10:00-10:30 local, with a 30-minute buffer after — occupies
        // the studio until 11:00, so every remaining 09:xx/10:xx candidate
        // slot that would overlap [09:xx, 11:00) is excluded, not just the
        // literal 10:00-10:30 window.
        $start = CarbonImmutable::create($date->year, $date->month, $date->day, 10, 0, 0, 'America/Toronto');
        $end = $start->addMinutes(30);
        Appointment::factory()->create([
            'tenant_id' => $tenant->id,
            'staff_id' => $staff->id,
            'service_id' => $service->id,
            'customer_id' => Customer::factory()->create(['tenant_id' => $tenant->id]),
            'appointment_range' => sprintf('[%s,%s)', $start->utc()->toIso8601String(), $end->utc()->toIso8601String()),
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 30,
            'status' => 'confirmed',
        ]);
    });

    $response = getJson("/api/tenants/{$tenant->slug}/availability?".http_build_query([
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'from' => $date->toDateString(),
        'to' => $date->toDateString(),
    ]));

    $response->assertOk();
    $starts = collect($response->json('slots'))
        ->map(fn ($slot) => CarbonImmutable::parse($slot['starts_at'])->setTimezone('America/Toronto')->format('H:i'))
        ->all();

    // The booked slot itself and every slot whose 60-minute service would
    // overlap [10:00, 11:00) (the buffered occupancy window) are gone.
    expect($starts)->not->toContain('09:15', '09:30', '09:45', '10:00');
    expect($starts)->toContain('09:00'); // ends 10:00, exactly touches the buffered window without overlapping it
});

test('a full-day availability exception removes every slot for that date without affecting other dates', function () {
    [$tenant, $staff, $service] = availabilityTenant();
    $blockedDate = aFutureDate(4);
    $openDate = $blockedDate->addDay();

    TenantContext::run($tenant->id, function () use ($tenant, $staff, $blockedDate, $openDate) {
        foreach ([$blockedDate, $openDate] as $date) {
            StaffWorkingHour::factory()->create([
                'tenant_id' => $tenant->id,
                'staff_id' => $staff->id,
                'day_of_week' => $date->dayOfWeek,
                'start_time' => '09:00:00',
                'end_time' => '11:00:00',
            ]);
        }

        AvailabilityException::factory()->create([
            'tenant_id' => $tenant->id,
            'staff_id' => $staff->id,
            'date' => $blockedDate->toDateString(),
            'is_available' => false,
            'start_time' => null,
            'end_time' => null,
            'reason' => 'Holiday',
        ]);
    });

    $response = getJson("/api/tenants/{$tenant->slug}/availability?".http_build_query([
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'from' => $blockedDate->toDateString(),
        'to' => $openDate->toDateString(),
    ]));

    $response->assertOk();
    $dates = collect($response->json('slots'))
        ->map(fn ($slot) => CarbonImmutable::parse($slot['starts_at'])->setTimezone('America/Toronto')->toDateString())
        ->unique()
        ->all();

    expect($dates)->toBe([$openDate->toDateString()]);
});

test('an unknown service_id returns 404', function () {
    [$tenant, $staff] = availabilityTenant();
    $date = aFutureDate(1);

    getJson("/api/tenants/{$tenant->slug}/availability?".http_build_query([
        'service_id' => (string) Str::uuid(),
        'staff_id' => $staff->id,
        'from' => $date->toDateString(),
        'to' => $date->toDateString(),
    ]))->assertStatus(404)->assertJson(['error' => 'NOT_FOUND']);
});

test('an unknown staff_id returns 404', function () {
    [$tenant, , $service] = availabilityTenant();
    $date = aFutureDate(1);

    getJson("/api/tenants/{$tenant->slug}/availability?".http_build_query([
        'service_id' => $service->id,
        'staff_id' => (string) Str::uuid(),
        'from' => $date->toDateString(),
        'to' => $date->toDateString(),
    ]))->assertStatus(404)->assertJson(['error' => 'NOT_FOUND']);
});

test('omitting staff_id searches across every active staff member', function () {
    [$tenant, $staffA, $service] = availabilityTenant();
    $date = aFutureDate(5);

    $staffB = TenantContext::run($tenant->id, function () use ($tenant, $staffA, $date) {
        StaffWorkingHour::factory()->create([
            'tenant_id' => $tenant->id,
            'staff_id' => $staffA->id,
            'day_of_week' => $date->dayOfWeek,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);

        $staffB = Staff::factory()->create(['tenant_id' => $tenant->id]);
        StaffWorkingHour::factory()->create([
            'tenant_id' => $tenant->id,
            'staff_id' => $staffB->id,
            'day_of_week' => $date->dayOfWeek,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);

        return $staffB;
    });

    $response = getJson("/api/tenants/{$tenant->slug}/availability?".http_build_query([
        'service_id' => $service->id,
        'from' => $date->toDateString(),
        'to' => $date->toDateString(),
    ]));

    $response->assertOk();
    $staffIds = collect($response->json('slots'))->pluck('staff_id')->unique()->sort()->values()->all();
    expect($staffIds)->toBe(collect([$staffA->id, $staffB->id])->sort()->values()->all());
});

test('a date range exceeding the configured lookahead returns 422 VALIDATION_FAILED', function () {
    [$tenant, $staff, $service] = availabilityTenant();
    $from = aFutureDate(1);
    $to = $from->addDays((int) config('booking.availability_max_lookahead_days') + 1);

    $response = getJson("/api/tenants/{$tenant->slug}/availability?".http_build_query([
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'from' => $from->toDateString(),
        'to' => $to->toDateString(),
    ]));

    $response->assertStatus(422);
    $response->assertJsonPath('error', 'VALIDATION_FAILED');
});
