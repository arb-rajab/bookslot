<?php

use App\Http\Controllers\Api\Admin\AppointmentController as AdminAppointmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AvailabilityController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\ManageBookingController;
use App\Http\Controllers\Api\MandateController;
use App\Http\Controllers\Api\Owner\AppointmentController as OwnerAppointmentController;
use App\Http\Controllers\Api\Owner\ServiceController as OwnerServiceController;
use App\Http\Controllers\Api\PaymentConfirmationController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\Staff\AppointmentController as StaffAppointmentController;
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

// D-0033 (amends D-0027/D-0030's principle to a second Stripe-touching
// route): deliberately NOT under `tenant.context` — this controller now
// re-checks the PaymentIntent against Stripe mid-request, and must not hold
// a database transaction open across that external call any more than
// booking-creation may. `resolve.tenant.token:confirm_payment` alone puts
// tenant_id/token_payload on the request; the controller opens its own
// short, explicit TenantContext::run() calls around the Stripe call.
Route::middleware(['resolve.tenant.token:confirm_payment'])
    ->post('bookings/{token}/confirm-payment', [PaymentConfirmationController::class, 'store']);

Route::post('admin/login', [AuthController::class, 'adminLogin']);

Route::middleware(['auth'])->post('logout', [AuthController::class, 'logout']);

Route::prefix('owner')
    ->middleware(['auth.tenant', 'role:owner'])
    ->group(function () {
        Route::post('services', [OwnerServiceController::class, 'store']);
        Route::get('appointments', [OwnerAppointmentController::class, 'index']);
        Route::patch('appointments/{id}/status', [OwnerAppointmentController::class, 'updateStatus']);
    });

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
