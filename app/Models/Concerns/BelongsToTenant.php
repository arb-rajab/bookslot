<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applied to every tenant-scoped model (04-data-model.md's tenancy
 * boundary section, D-0005). Adds the app-layer global scope and
 * auto-fills tenant_id from the active TenantContext on create, so
 * application code isn't responsible for remembering to set it on every
 * insert — the same "don't trust every call site to get this right"
 * reasoning that motivates the RLS backstop in the first place.
 */
trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            if ($model->tenant_id === null && CurrentTenant::id() !== null) {
                $model->tenant_id = CurrentTenant::id();
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
