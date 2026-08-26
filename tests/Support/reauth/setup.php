<?php

use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/bootstrap.php';

bootReauthProbeApp();

$tenant = Tenant::factory()->create();

TenantContext::run($tenant->id, function () use ($tenant) {
    User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => 'owner',
        'email' => 'owner@example.test',
        'password_hash' => Hash::make('correct-password'),
    ]);
});

// Committed for real (this process has no RefreshDatabase transaction
// wrapping it) — readable by the login probe and the second-request probe,
// each its own separate process/connection, same reasoning as
// tests/Support/concurrency/setup.php.
echo json_encode(['slug' => $tenant->slug]);
