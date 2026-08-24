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
 * @property string $appointment_id
 * @property string $type
 * @property string $stripe_payment_intent_id
 * @property string|null $stripe_charge_id
 * @property int $amount
 * @property string $currency
 * @property int|null $application_fee_amount
 * @property string $status
 * @property string|null $failure_code
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Tenant $tenant
 * @property-read Appointment|null $appointment
 */
class Payment extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'appointment_id',
        'type',
        'stripe_payment_intent_id',
        'stripe_charge_id',
        'amount',
        'currency',
        'application_fee_amount',
        'status',
        'failure_code',
    ];

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}
