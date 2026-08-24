<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * The tenancy boundary itself, not a tenant-scoped table — see
 * 04-data-model.md's tenancy boundary section. No RLS, no TenantScope.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string $timezone
 * @property string $currency
 * @property string|null $stripe_connect_account_id
 * @property string $stripe_onboarding_status
 * @property Carbon|null $deleted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Tenant extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'timezone',
        'currency',
        'stripe_connect_account_id',
        'stripe_onboarding_status',
    ];
}
