<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// D-0050 (docs/project-memory/09-decision-log.md): DispatchAppointmentRemindersCommand
// schedules at most one notification_deliveries row per (appointment_id,
// purpose) — a plain application-level check-then-create is not enough to
// guarantee that under two overlapping scheduler runs (this command taking
// longer than its own polling interval); a real unique constraint is what
// actually makes the command's own claimed idempotency true rather than
// merely usual. tenant_id is included for defense in depth (matches this
// project's general preference for tenant-scoped uniqueness, D-0009), even
// though appointment_id alone already determines the tenant.
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX notification_deliveries_tenant_appointment_purpose_unique
              ON notification_deliveries (tenant_id, appointment_id, purpose);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP INDEX IF EXISTS notification_deliveries_tenant_appointment_purpose_unique;');
    }
};
