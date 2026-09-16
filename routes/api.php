<?php

use App\Http\Controllers\Api\Admin\AppointmentController as AdminAppointmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AvailabilityController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\ManageBookingController;
use App\Http\Controllers\Api\MandateController;
use App\Http\Controllers\Api\Owner\AppointmentController as OwnerAppointmentController;
use App\Http\Controllers\Api\Owner\AvailabilityExceptionController as OwnerAvailabilityExceptionController;
use App\Http\Controllers\Api\Owner\CustomerController as OwnerCustomerController;
use App\Http\Controllers\Api\Owner\NotificationController as OwnerNotificationController;
use App\Http\Controllers\Api\Owner\QueueHealthController as OwnerQueueHealthController;
use App\Http\Controllers\Api\Owner\ServiceController as OwnerServiceController;
use App\Http\Controllers\Api\Owner\StaffController as OwnerStaffController;
use App\Http\Controllers\Api\Owner\StripeConnectController as OwnerStripeConnectController;
use App\Http\Controllers\Api\Owner\WorkingHourController as OwnerWorkingHourController;
use App\Http\Controllers\Api\PaymentConfirmationController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\Staff\AppointmentController as StaffAppointmentController;
use App\Http\Controllers\Api\StripeWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Public routes cover D-0009's two original tenant-resolution mechanisms:
| slug-based, and the signed-token capability class (generalized by
| D-0021). Authenticated owner/staff routes use `auth.tenant` (D-0043) — a
| single consolidated middleware, not a separate auth/tenant-resolution
| pair — platform-admin keeps its own separate auth/role/resolve.tenant.
| impersonate pipeline. See bootstrap/app.php's middleware-alias comment
| for the full rationale.
|
*/

Route::prefix('tenants/{slug}')
    ->middleware(['resolve.tenant.slug', 'tenant.context'])
    ->group(function () {
        Route::get('services', [ServiceController::class, 'index']);
        Route::get('services/{service}/mandate', [MandateController::class, 'show']);
        Route::get('availability', [AvailabilityController::class, 'index']);
        Route::post('login', [AuthController::class, 'login']);
    });

// D-0027/D-0030: deliberately NOT under the group above — `tenant.context`
// wraps the entire request in one transaction, which is exactly what this
// route must not do (it calls Stripe mid-request). `resolve.tenant.slug`
// alone puts tenant_id onto the request; BookingController opens its own
// short, explicit TenantContext::run() calls around the Stripe call.
Route::middleware(['resolve.tenant.slug'])
    ->post('tenants/{slug}/bookings', [BookingController::class, 'store']);

Route::middleware(['resolve.tenant.token:manage_booking', 'tenant.context'])
    ->get('bookings/manage/{token}', [ManageBookingController::class, 'show']);

// D-0052: customer-facing self-service cancellation, reusing the same
// manage_booking token (and the same middleware pair) as the lookup route
// above — no new token purpose, no new issuance path. Bookkeeping only
// (status + audit trail), same discipline as
// Owner\AppointmentController::cancel — never touches Stripe, so this is
// safe under `tenant.context`'s whole-request transaction wrap.
Route::middleware(['resolve.tenant.token:manage_booking', 'tenant.context'])
    ->post('bookings/manage/{token}/cancel', [ManageBookingController::class, 'cancel']);

// D-0033 (amends D-0027/D-0030's principle to a second Stripe-touching
// route): deliberately NOT under `tenant.context` — this controller now
// re-checks the PaymentIntent against Stripe mid-request, and must not hold
// a database transaction open across that external call any more than
// booking-creation may. `resolve.tenant.token:confirm_payment` alone puts
// tenant_id/token_payload on the request; the controller opens its own
// short, explicit TenantContext::run() calls around the Stripe call.
Route::middleware(['resolve.tenant.token:confirm_payment'])
    ->post('bookings/{token}/confirm-payment', [PaymentConfirmationController::class, 'store']);

// D-0048: no tenant-resolution middleware at all — see
// StripeWebhookController's own docblock for why (tenant context is
// recovered from the verified event payload inside the dispatched job, not
// from any route mechanism). Never behind `tenant.context`/`auth.tenant`:
// this request carries no session, no slug, no signed token.
Route::post('webhooks/stripe', [StripeWebhookController::class, 'store']);

Route::post('admin/login', [AuthController::class, 'adminLogin']);

Route::middleware(['auth'])->post('logout', [AuthController::class, 'logout']);

Route::prefix('owner')
    ->middleware(['auth.tenant', 'role:owner'])
    ->group(function () {
        Route::get('services', [OwnerServiceController::class, 'index']);
        Route::post('services', [OwnerServiceController::class, 'store']);
        Route::patch('services/{service}', [OwnerServiceController::class, 'update']);

        Route::get('staff', [OwnerStaffController::class, 'index']);
        Route::post('staff', [OwnerStaffController::class, 'store']);
        Route::patch('staff/{staff}', [OwnerStaffController::class, 'update']);

        Route::get('staff/{staff}/working-hours', [OwnerWorkingHourController::class, 'index']);
        Route::put('staff/{staff}/working-hours', [OwnerWorkingHourController::class, 'replace']);

        Route::get('staff/{staff}/availability-exceptions', [OwnerAvailabilityExceptionController::class, 'index']);
        Route::post('staff/{staff}/availability-exceptions', [OwnerAvailabilityExceptionController::class, 'store']);
        Route::delete('staff/{staff}/availability-exceptions/{exception}', [OwnerAvailabilityExceptionController::class, 'destroy']);

        Route::get('appointments', [OwnerAppointmentController::class, 'index']);
        Route::get('appointments/{id}', [OwnerAppointmentController::class, 'show']);
        Route::patch('appointments/{id}/status', [OwnerAppointmentController::class, 'updateStatus']);
        Route::post('appointments/{id}/cancel', [OwnerAppointmentController::class, 'cancel']);

        Route::get('notifications', [OwnerNotificationController::class, 'index']);

        Route::get('queue-health', [OwnerQueueHealthController::class, 'show']);

        // FR-18/D-0059: neither action calls Stripe (D-0022: erasure never
        // touches payment_mandates, the only Stripe-ID-bearing table a
        // customer's data reaches; export is read-only) — plain
        // `auth.tenant` is correct here, unlike refund/balance-charge/
        // Connect, which all need `auth.tenant.external` specifically
        // because they call Stripe mid-request.
        Route::get('customers/{id}/export', [OwnerCustomerController::class, 'export']);
        Route::post('customers/{id}/erasure', [OwnerCustomerController::class, 'erase']);

        // FR-23/D-0014/D-0023/D-0060: no external call mid-request (no
        // Stripe, no other outbound dependency) — stays on plain
        // `auth.tenant`, unlike refund/balance-charge/Connect below, which
        // all needed `auth.tenant.external` specifically because they call
        // Stripe.
        Route::post('customers/{id}/re-invite', [OwnerCustomerController::class, 'reinvite']);
    });

// D-0056 (docs/project-memory/09-decision-log.md, amends D-0027 to a second
// owner-facing route): deliberately NOT inside the `owner` group above —
// `auth.tenant` wraps the whole request in one transaction, which this
// route must not hold open across its Stripe refund call. `auth.tenant.
// external` performs the identical session-based owner auth without that
// wrap; the controller opens its own short, explicit TenantContext::run()
// calls around the Stripe call, same discipline as BookingController/
// PaymentConfirmationController.
Route::middleware(['auth.tenant.external', 'role:owner'])
    ->post('owner/appointments/{id}/refund', [OwnerAppointmentController::class, 'refund']);

// D-0057 (docs/project-memory/09-decision-log.md, amends D-0027 to a third
// owner-facing route): same reasoning as the refund route immediately
// above — this endpoint calls Stripe (an off-session balance charge, J5)
// and must not hold `auth.tenant`'s whole-request transaction open across
// that call.
Route::middleware(['auth.tenant.external', 'role:owner'])
    ->post('owner/appointments/{id}/balance/charge', [OwnerAppointmentController::class, 'chargeBalance']);

// D-0058 (docs/project-memory/09-decision-log.md, amends D-0027 to a
// fourth/fifth owner-facing route): same reasoning as refund/balance-charge
// immediately above — both actions call Stripe (Connect account/Account
// Link creation, and a live account-status read) and must not hold
// `auth.tenant`'s whole-request transaction open across either call.
Route::middleware(['auth.tenant.external', 'role:owner'])
    ->post('owner/stripe/connect/onboarding-link', [OwnerStripeConnectController::class, 'onboardingLink']);

Route::middleware(['auth.tenant.external', 'role:owner'])
    ->get('owner/stripe/connect/status', [OwnerStripeConnectController::class, 'status']);

Route::prefix('staff')
    ->middleware(['auth.tenant', 'role:staff'])
    ->group(function () {
        Route::get('appointments', [StaffAppointmentController::class, 'index']);
    });

Route::prefix('admin/tenants/{tenant}')
    ->middleware(['auth', 'role:platform_admin', 'resolve.tenant.impersonate', 'tenant.context'])
    ->group(function () {
        Route::get('appointments', [AdminAppointmentController::class, 'index']);
    });
