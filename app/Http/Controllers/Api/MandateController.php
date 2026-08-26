<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mandates\MandateRenderer;
use App\Models\Service;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/tenants/{slug}/services/{service}/mandate — the pre-submission
 * mandate display this project didn't previously have an endpoint for
 * (05-api-contracts.md's Session 9 amendment flagged the gap: nothing
 * served the mandate text a customer must see before the consent
 * checkbox). Renders via the same MandateRenderer the booking-creation
 * path uses (D-0030) — never a second, independently-maintained renderer.
 *
 * The tenant is already resolved onto the active TenantContext by
 * ResolveTenantFromSlug + SetTenantContext before this runs (same as
 * ServiceController). {service} is deliberately NOT implicit-route-model-
 * bound — Laravel's `api` middleware group bakes SubstituteBindings in at
 * a fixed position that runs BEFORE this route's own
 * resolve.tenant.slug/tenant.context middleware, so an implicit binding
 * would resolve Service's tenant-scoped query with no context set yet
 * (always a 404, regardless of which tenant actually owns the row) —
 * found by executing this, not by reading the pipeline order. Looked up
 * manually instead, same pattern as every other controller here
 * (ManageBookingController, PaymentConfirmationController).
 *
 * Takes both route parameters positionally ($slug, $serviceId) even
 * though $slug is unused — found by executing this, too: Laravel binds a
 * scalar (non-class-typed) controller parameter to a route parameter by
 * POSITION, not by name, so a single `$service` argument would actually
 * receive the route's first segment ({slug}), not {service}.
 */
class MandateController extends Controller
{
    public function __construct(private readonly MandateRenderer $renderer) {}

    public function show(string $slug, string $serviceId): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail(CurrentTenant::id());
        $service = Service::query()->findOrFail($serviceId);

        return response()->json($this->renderer->render($tenant, $service));
    }
}
