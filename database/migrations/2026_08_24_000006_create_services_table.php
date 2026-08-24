<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// buffer_before_minutes/buffer_after_minutes are required, with no DEFAULT,
// per D-0012 — an INSERT that omits either fails rather than silently
// defaulting to zero back-to-back scheduling.
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE services (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants (id),
                name text NOT NULL,
                duration_minutes integer NOT NULL
                    CONSTRAINT services_duration_positive CHECK (duration_minutes > 0),
                price_amount integer NOT NULL,
                currency char(3) NOT NULL,
                deposit_type text NOT NULL
                    CONSTRAINT services_deposit_type_check CHECK (deposit_type IN ('fixed', 'percentage')),
                deposit_fixed_amount integer NULL,
                deposit_percentage_bps integer NULL,
                buffer_before_minutes integer NOT NULL
                    CONSTRAINT services_buffer_before_nonneg CHECK (buffer_before_minutes >= 0)
                    CONSTRAINT services_buffer_before_max CHECK (buffer_before_minutes <= 1440),
                buffer_after_minutes integer NOT NULL
                    CONSTRAINT services_buffer_after_nonneg CHECK (buffer_after_minutes >= 0)
                    CONSTRAINT services_buffer_after_max CHECK (buffer_after_minutes <= 1440),
                is_active boolean NOT NULL DEFAULT true,
                deleted_at timestamptz NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT services_deposit_amount_matches_type CHECK (
                    (deposit_type = 'fixed' AND deposit_fixed_amount IS NOT NULL AND deposit_percentage_bps IS NULL)
                    OR
                    (deposit_type = 'percentage' AND deposit_percentage_bps IS NOT NULL AND deposit_fixed_amount IS NULL)
                )
            );

            CREATE INDEX services_tenant_active_idx ON services (tenant_id, is_active);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS services;');
    }
};
