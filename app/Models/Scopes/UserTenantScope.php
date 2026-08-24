<?php

namespace App\Models\Scopes;

use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * users is tenant-scoped only for role IN ('owner','staff') — a
 * platform_admin row has tenant_id NULL by design and must remain visible
 * regardless of the current tenant context (04-data-model.md's tenancy
 * boundary section; mirrors the widened RLS policy on this table in the
 * enable-RLS migration). Every other model uses the plain TenantScope.
 */
class UserTenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = CurrentTenant::id();

        if ($tenantId === null) {
            $builder->where($model->qualifyColumn('role'), '=', 'platform_admin');

            return;
        }

        $builder->where(function (Builder $query) use ($model, $tenantId) {
            $query->where($model->qualifyColumn('role'), '=', 'platform_admin')
                ->orWhere($model->qualifyColumn('tenant_id'), '=', $tenantId);
        });
    }
}
