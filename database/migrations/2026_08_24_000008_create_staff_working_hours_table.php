<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE staff_working_hours (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants (id),
                staff_id uuid NOT NULL REFERENCES staff (id) ON DELETE RESTRICT,
                day_of_week smallint NOT NULL
                    CONSTRAINT staff_working_hours_day_of_week_check CHECK (day_of_week BETWEEN 0 AND 6),
                start_time time NOT NULL,
                end_time time NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT staff_working_hours_end_after_start CHECK (end_time > start_time)
            );

            CREATE INDEX staff_working_hours_tenant_staff_day_idx ON staff_working_hours (tenant_id, staff_id, day_of_week);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS staff_working_hours;');
    }
};
