<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The core table (04-data-model.md). `starts_at`/`ends_at`/`occupancy_range`
 * are database-generated (STORED) columns — deliberately absent from
 * $fillable, since Postgres rejects an explicit INSERT into a generated
 * column. `appointment_range` is a plain tstzrange text literal at this
 * layer (e.g. "[2026-09-01T09:00:00+00,2026-09-01T10:00:00+00)") — no
 * dedicated PHP range value object exists yet; that's future-session work
 * once real booking-creation logic is built, not needed for the
 * tenant-isolation suite this session delivers.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $staff_id
 * @property string $service_id
 * @property string $customer_id
 * @property string $appointment_range
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property int $buffer_before_minutes
 * @property int $buffer_after_minutes
 * @property string $occupancy_range
 * @property string $status
 * @property string|null $cancelled_by
 * @property string|null $cancelled_reason
 * @property Carbon|null $cancelled_at
 * @property string|null $notes
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Tenant $tenant
 * @property-read Staff|null $staff
 * @property-read Service|null $service
 * @property-read Customer|null $customer
 */
class Appointment extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'staff_id',
        'service_id',
        'customer_id',
        'appointment_range',
        'buffer_before_minutes',
        'buffer_after_minutes',
        'status',
        'cancelled_by',
        'cancelled_reason',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
