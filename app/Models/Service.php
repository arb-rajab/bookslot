<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property int $duration_minutes
 * @property int $price_amount
 * @property string $currency
 * @property string $deposit_type
 * @property int|null $deposit_fixed_amount
 * @property int|null $deposit_percentage_bps
 * @property int $buffer_before_minutes
 * @property int $buffer_after_minutes
 * @property bool $is_active
 * @property Carbon|null $deleted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Tenant $tenant
 */
class Service extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'duration_minutes',
        'price_amount',
        'currency',
        'deposit_type',
        'deposit_fixed_amount',
        'deposit_percentage_bps',
        'buffer_before_minutes',
        'buffer_after_minutes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
