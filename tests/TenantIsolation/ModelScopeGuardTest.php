<?php

use App\Models\Concerns\BelongsToTenant;
use App\Models\Scopes\UserTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * RlsManifestTest guards the database side (a table shipped without RLS).
 * This is the missing application-side counterpart: a model backed by a
 * tenant-scoped table but missing BelongsToTenant (or, for `users`,
 * UserTenantScope) is invisible to that check entirely, since RLS would
 * still protect it — the gap this test closes is app-layer defense in depth
 * and the app-layer scope's own auto-fill-on-create behavior, not RLS.
 *
 * Parameterized over the live app/Models directory and
 * config('tenancy.tenant_scoped_tables'), same discipline as
 * RlsManifestTest — a new tenant-scoped model added next month is covered
 * automatically, not by remembering to update a hand-written list here.
 */
test('every Eloquent model backed by a tenant-scoped table uses the tenant scope guard', function () {
    $tenantScopedTables = config('tenancy.tenant_scoped_tables');
    $modelFiles = glob(app_path('Models/*.php'));

    expect($modelFiles)->not->toBeEmpty();

    $matchedTables = [];

    foreach ($modelFiles as $file) {
        $class = 'App\\Models\\'.basename($file, '.php');
        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
            continue;
        }

        $table = (new $class)->getTable();

        if (! in_array($table, $tenantScopedTables, true)) {
            continue;
        }

        $matchedTables[] = $table;

        if ($class === User::class) {
            // users is tenant-scoped only for role IN ('owner','staff') — a
            // platform_admin row has tenant_id NULL by design, so it uses
            // the widened UserTenantScope directly rather than
            // BelongsToTenant (04-data-model.md's tenancy boundary note).
            expect(array_key_exists(UserTenantScope::class, (new User)->getGlobalScopes()))->toBeTrue(
                'App\\Models\\User is backed by the tenant-scoped users table but does not register UserTenantScope.'
            );

            continue;
        }

        expect(array_key_exists(BelongsToTenant::class, class_uses_recursive($class)))->toBeTrue(
            "{$class} is backed by tenant-scoped table [{$table}] but does not use App\\Models\\Concerns\\BelongsToTenant."
        );
    }

    // Every manifest table should be reachable from at least one model file
    // in app/Models — otherwise this test could pass trivially having
    // checked nothing for a table with no model class at all.
    foreach ($tenantScopedTables as $table) {
        expect(in_array($table, $matchedTables, true))->toBeTrue(
            "No model in app/Models is backed by manifest table [{$table}]."
        );
    }
});
