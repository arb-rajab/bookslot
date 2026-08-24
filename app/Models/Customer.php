<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * No deleted_at — erasure anonymizes in place rather than soft-deleting
 * (04-data-model.md's soft/hard delete matrix).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string|null $notes
 * @property Carbon|null $erasure_requested_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Tenant $tenant
 */
class Customer extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'phone',
        'notes',
        'erasure_requested_at',
    ];

    protected function casts(): array
    {
        return [
            'erasure_requested_at' => 'datetime',
        ];
    }
}
