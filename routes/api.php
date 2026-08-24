<?php

use App\Http\Controllers\Api\ManageBookingController;
use App\Http\Controllers\Api\PaymentConfirmationController;
use App\Http\Controllers\Api\ServiceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| First real routes/controllers in this repository — every prior session
| built no routes at all (docs/project-memory/12-session-handoff.md).
| Covers the two D-0009 tenant-resolution mechanisms this session's scope
| supports without inventing an undecided auth mechanism: slug-based, and
| the signed-token capability class (generalized by D-0021). Authenticated
| owner/staff routes and the platform-admin impersonation path are
| deliberately absent — 05-api-contracts.md's auth token mechanics for
| those actors are still an open item, not decided here (see this
| session's report/handoff for the raised gap).
|
*/

Route::prefix('tenants/{slug}')
    ->middleware(['resolve.tenant.slug', 'tenant.context'])
    ->group(function () {
        Route::get('services', [ServiceController::class, 'index']);
    });

Route::middleware(['resolve.tenant.token:manage_booking', 'tenant.context'])
    ->get('bookings/manage/{token}', [ManageBookingController::class, 'show']);

Route::middleware(['resolve.tenant.token:confirm_payment', 'tenant.context'])
    ->post('bookings/{token}/confirm-payment', [PaymentConfirmationController::class, 'store']);
