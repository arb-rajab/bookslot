<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE booking_events (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants (id),
                appointment_id uuid NULL REFERENCES appointments (id) ON DELETE RESTRICT,
                actor_type text NOT NULL
                    CONSTRAINT booking_events_actor_type_check
                    CHECK (actor_type IN ('owner', 'staff', 'customer', 'system', 'webhook')),
                actor_id uuid NULL,
                event_type text NOT NULL,
                from_status text NULL,
                to_status text NULL,
                metadata jsonb NULL,
                created_at timestamptz NOT NULL DEFAULT now()
            );

            CREATE INDEX booking_events_tenant_appointment_created_idx ON booking_events (tenant_id, appointment_id, created_at);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS booking_events;');
    }
};
