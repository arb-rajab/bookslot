<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * D-0010, amended by D-0022: never modified or deleted by a customer
 * erasure — see the migration's own note. No $timestamps update column;
 * this table has created_at only (immutable evidentiary record).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $appointment_id
 * @property string $mandate_text
 * @property string $mandate_template_version
 * @property int $balance_amount_disclosed
 * @property Carbon $accepted_at
 * @property string $accepted_ip
 * @property string|null $accepted_user_agent
 * @property string $stripe_payment_intent_id
 * @property string $stripe_payment_method_id
 * @property Carbon $created_at
 * @property-read Tenant $tenant
 * @property-read Appointment|null $appointment
 */
class PaymentMandate extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'appointment_id',
        'mandate_text',
        'mandate_template_version',
        'balance_amount_disclosed',
        'accepted_at',
        'accepted_ip',
        'accepted_user_agent',
        'stripe_payment_intent_id',
        'stripe_payment_method_id',
    ];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
        ];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}
