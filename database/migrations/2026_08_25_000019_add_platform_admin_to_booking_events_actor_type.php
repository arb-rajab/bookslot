<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// D-0028 (docs/project-memory/09-decision-log.md): adds platform_admin to
// booking_events.actor_type's CHECK list, for the cross-tenant admin-
// impersonation audit path (D-0009). A new migration, not an edit to
// 2026_08_24_000016_create_booking_events_table.php, which already ran
// against real Postgres in every prior session.
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE booking_events DROP CONSTRAINT booking_events_actor_type_check;
            ALTER TABLE booking_events
                ADD CONSTRAINT booking_events_actor_type_check
                CHECK (actor_type IN ('owner', 'staff', 'customer', 'system', 'webhook', 'platform_admin'));
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE booking_events DROP CONSTRAINT booking_events_actor_type_check;
            ALTER TABLE booking_events
                ADD CONSTRAINT booking_events_actor_type_check
                CHECK (actor_type IN ('owner', 'staff', 'customer', 'system', 'webhook'));
        SQL);
    }
};
