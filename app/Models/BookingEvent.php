<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Audit trail — never deleted, no updated_at (04-data-model.md).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $appointment_id
 * @property string $actor_type
 * @property string|null $actor_id
 * @property string $event_type
 * @property string|null $from_status
 * @property string|null $to_status
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 * @property-read Tenant $tenant
 * @property-read Appointment|null $appointment
 */
class BookingEvent extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'appointment_id',
        'actor_type',
        'actor_id',
        'event_type',
        'from_status',
        'to_status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}
