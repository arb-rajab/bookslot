<?php

use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Tenancy\TenantContext;

require __DIR__.'/bootstrap.php';

$app = bootProbeApp();

$tenant = Tenant::factory()->create();

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
