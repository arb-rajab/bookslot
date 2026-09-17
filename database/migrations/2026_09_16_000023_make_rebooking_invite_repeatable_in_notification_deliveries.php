<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// D-0059 (docs/project-memory/09-decision-log.md): the unique index this
// table gained in 2026_09_13_000021 was reasoned entirely around
// reminder_7d/24h/2h's fire-once guarantee (D-0050) — one row per
// (tenant_id, appointment_id, purpose) so an overlapping scheduler run can
// never double-schedule the same reminder. FR-23/D-0014's manual re-invite
// action is a different shape: an owner may deliberately trigger it more
// than once for the same customer/appointment ("at their own discretion"),
// and each call is its own real send, not a state an owner "sets" once.
// Enforcing the same one-row-per-appointment-per-purpose rule against
// 'rebooking_invite' would silently turn every second re-invite click into
// a 500 (23505 unique violation) with no application-level handling for
// it, contradicting FR-23's own repeatable framing. Narrowing the index to
// a partial one — excluding 'rebooking_invite' — keeps the original
// fire-once guarantee fully intact for every purpose that still needs it
// (including the still-unbuilt automatic 'rebooking_prompt', J10, which
// *does* need to stay fire-once) while letting 'rebooking_invite' insert
// freely, once per owner click.
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS notification_deliveries_tenant_appointment_purpose_unique;

            CREATE UNIQUE INDEX notification_deliveries_tenant_appointment_purpose_unique
              ON notification_deliveries (tenant_id, appointment_id, purpose)
              WHERE purpose <> 'rebooking_invite';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS notification_deliveries_tenant_appointment_purpose_unique;

            CREATE UNIQUE INDEX notification_deliveries_tenant_appointment_purpose_unique
              ON notification_deliveries (tenant_id, appointment_id, purpose);
        SQL);
    }
};
