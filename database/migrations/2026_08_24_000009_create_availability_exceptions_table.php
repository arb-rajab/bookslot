<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE availability_exceptions (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants (id),
                staff_id uuid NULL REFERENCES staff (id) ON DELETE RESTRICT,
                date date NOT NULL,
                is_available boolean NOT NULL,
                start_time time NULL,
                end_time time NULL,
                reason text NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now()
            );

            CREATE INDEX availability_exceptions_tenant_staff_date_idx ON availability_exceptions (tenant_id, staff_id, date);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS availability_exceptions;');
    }
};
