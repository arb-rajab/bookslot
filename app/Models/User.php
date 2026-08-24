<?php

namespace App\Models;

use App\Models\Scopes\UserTenantScope;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * Login identities: owners, staff logins, platform admins
 * (04-data-model.md). Tenant-scoped only for role IN ('owner','staff') — a
 * platform_admin row has tenant_id NULL by design, so this uses
 * UserTenantScope rather than the generic BelongsToTenant trait.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $role
 * @property string $name
 * @property string $email
 * @property string $password_hash
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $deleted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Tenant|null $tenant
 */
class User extends Authenticatable
{
    use HasFactory, HasUuids, Notifiable, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'role',
        'name',
        'email',
        'password_hash',
        'email_verified_at',
    ];

    protected $hidden = [
        'password_hash',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new UserTenantScope);
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password_hash' => 'hashed',
        ];
    }

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    // Schema deliberately has no remember_token column (04-data-model.md) —
    // "remember me" login isn't part of this session's or 04's design.
    public function getRememberTokenName(): string
    {
        return '';
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
