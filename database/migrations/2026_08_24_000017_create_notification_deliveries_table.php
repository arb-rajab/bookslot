<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// purpose includes 'rebooking_invite' (D-0023) as a value distinct from the
// automatic 'rebooking_prompt' — kept separate so the audit trail can
// always tell a manual owner re-invite from a system-triggered one.
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE notification_deliveries (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants (id),
                appointment_id uuid NOT NULL REFERENCES appointments (id) ON DELETE RESTRICT,
                purpose text NOT NULL
                    CONSTRAINT notification_deliveries_purpose_check CHECK (purpose IN (
                        'reminder_7d', 'reminder_24h', 'reminder_2h', 'rebooking_prompt', 'rebooking_invite'
                    )),
                channel text NOT NULL
                    CONSTRAINT notification_deliveries_channel_check CHECK (channel IN ('email', 'sms')),
                scheduled_for timestamptz NOT NULL,
                sent_at timestamptz NULL,
                status text NOT NULL DEFAULT 'scheduled'
                    CONSTRAINT notification_deliveries_status_check
                    CHECK (status IN ('scheduled', 'sent', 'failed', 'cancelled')),
                provider_message_id text NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now()
            );

            CREATE INDEX notification_deliveries_tenant_appt_purpose_idx ON notification_deliveries (tenant_id, appointment_id, purpose);
            CREATE INDEX notification_deliveries_scheduled_status_idx ON notification_deliveries (scheduled_for, status);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS notification_deliveries;');
    }
};
