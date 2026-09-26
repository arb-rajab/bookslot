<?php

use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;

require __DIR__.'/bootstrap.php';

$app = bootProbeApp();

// D-0067: a null stripe_connect_account_id (Tenant::factory()'s own
// default) now gets BookingController::store() rejected with 409
// BOOKING_UNAVAILABLE before the exclusion-constraint race this probe
// exists to exercise even matters — give this tenant a connected account so
// the two real-process probes still race on SLOT_ALREADY_BOOKED, not on the
// unrelated no-Stripe-account gate. A random-per-run id, NOT the shared
// 'acct_fake_connected' literal every other test file uses (Owner
// AppointmentControllerTest.php's ownerAndTenant(), BookingControllerTest.
// php's bookingTenant()): this row is genuinely, permanently committed by a
// real standalone process, outside any Pest RefreshDatabase transaction, so
// unlike every other test's own fixture it is never rolled back — a fixed
// literal here collides with the `tenants_stripe_connect_account_id_unique`
// partial index the moment any other test's own (properly-isolated, but
// running later in the same test-database lifetime) insert tries to reuse
// the same value. Found the hard way: a full-suite run reproducibly failed
// 40 unrelated tests with a real unique-constraint violation the instant
// this literal was reused here.
$tenant = Tenant::factory()->create([
    'stripe_connect_account_id' => 'acct_fake_concurrency_'.Str::random(16),
    'stripe_onboarding_status' => 'complete',
]);

[$service, $staff] = TenantContext::run($tenant->id, fn () => [
    Service::factory()->create([
        'tenant_id' => $tenant->id,
        'duration_minutes' => 60,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 15,
    ]),
    Staff::factory()->create(['tenant_id' => $tenant->id]),
]);

echo json_encode([
    'tenant_id' => $tenant->id,
    'slug' => $tenant->slug,
    'service_id' => $service->id,
    'staff_id' => $staff->id,
]);
