<?php

namespace App\Models\Scopes;

use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * D-0005's first enforcement layer (Laravel global scopes) — the second,
 * independent layer is the Postgres RLS policy on the same table. This
 * scope deliberately mirrors RLS's own fail-closed behavior: with no
 * tenant context set, it excludes every row rather than returning
 * everything, the same way `current_setting(..., true)` returns NULL and
 * matches zero rows at the database layer (04-data-model.md's tenancy
 * boundary section). Neither layer is allowed to fail open.
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = CurrentTenant::id();

        if ($tenantId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('tenant_id'), '=', $tenantId);
    }
}
