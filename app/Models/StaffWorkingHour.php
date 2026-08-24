<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $staff_id
 * @property int $day_of_week
 * @property string $start_time
 * @property string $end_time
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Tenant $tenant
 * @property-read Staff $staff
 */
class StaffWorkingHour extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $table = 'staff_working_hours';

    protected $fillable = [
        'tenant_id',
        'staff_id',
        'day_of_week',
        'start_time',
        'end_time',
    ];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}
