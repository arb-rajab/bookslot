<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * D-0048 (docs/project-memory/09-decision-log.md): the model for the
 * `stripe_webhook_events` table that has existed since migration
 * 2026_08_24_000015 but had no Eloquent model and nothing writing to or
 * reading from it until this decision. Deliberately NOT tenant-scoped
 * (no BelongsToTenant, no RLS on this table) — per that migration's own
 * comment, a webhook may arrive before this codebase knows which tenant it
 * maps to, so this row is written under no tenant GUC at all, before
 * ProcessStripeWebhookJob ever establishes one.
 *
 * `processed_at` is the single dedup/idempotency marker J9
 * (02-requirements.md) requires: non-null means "fully applied, do not
 * reprocess," covering both J9's duplicate-delivery and late-arrival cases
 * with one field rather than two.
 *
 * @property string $id
 * @property string $stripe_event_id
 * @property string $type
 * @property array<string, mixed> $payload
 * @property Carbon $received_at
 * @property Carbon|null $processed_at
 * @property string|null $processing_error
 */
class StripeWebhookEvent extends Model
{
    use HasFactory, HasUuids;

    // Neither created_at nor updated_at exists on this table — received_at
    // (set explicitly, DB-defaulted otherwise) and processed_at are its
    // only timestamp columns.
    public $timestamps = false;

    protected $fillable = [
        'stripe_event_id',
        'type',
        'payload',
        'received_at',
        'processed_at',
        'processing_error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
