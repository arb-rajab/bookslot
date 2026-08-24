<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// D-0007/D-0008: the double-booking guarantee. Checked against
// occupancy_range (buffer-aware), not the literal appointment_range, per
// D-0008 — otherwise two concurrent requests could each read availability
// correctly and still book buffer-violating adjacent slots. Split into its
// own migration, after appointments exists, because it depends on
// btree_gist (migration 000001) for GIST equality support on uuid columns.
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE appointments
              ADD CONSTRAINT no_overlapping_appointments
              EXCLUDE USING gist (
                tenant_id WITH =,
                staff_id WITH =,
                occupancy_range WITH &&
              ) WHERE (status NOT IN ('cancelled', 'no_show') AND status IS NOT NULL);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE appointments DROP CONSTRAINT IF EXISTS no_overlapping_appointments;');
    }
};
