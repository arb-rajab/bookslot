<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Created WITHOUT the exclusion constraint yet, per 04-data-model.md's
// migration order — that's added in its own migration once btree_gist is
// confirmed already present. buffer_before_minutes/buffer_after_minutes
// have no DEFAULT (D-0012): they are always populated explicitly by
// application code from the owning service's buffer configuration at
// booking-creation time. occupancy_range is a STORED generated column
// (D-0008, corrected per the D-0008 amendment) — appointment_range itself
// stays the literal, customer-facing window; buffer is never mixed into it.
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE appointments (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants (id),
                staff_id uuid NOT NULL REFERENCES staff (id) ON DELETE RESTRICT,
                service_id uuid NOT NULL REFERENCES services (id) ON DELETE RESTRICT,
                customer_id uuid NOT NULL REFERENCES customers (id) ON DELETE RESTRICT,
                appointment_range tstzrange NOT NULL,
                starts_at timestamptz GENERATED ALWAYS AS (lower(appointment_range)) STORED NOT NULL,
                ends_at timestamptz GENERATED ALWAYS AS (upper(appointment_range)) STORED NOT NULL,
                buffer_before_minutes integer NOT NULL
                    CONSTRAINT appointments_buffer_before_nonneg CHECK (buffer_before_minutes >= 0)
                    CONSTRAINT appointments_buffer_before_max CHECK (buffer_before_minutes <= 1440),
                buffer_after_minutes integer NOT NULL
                    CONSTRAINT appointments_buffer_after_nonneg CHECK (buffer_after_minutes >= 0)
                    CONSTRAINT appointments_buffer_after_max CHECK (buffer_after_minutes <= 1440),
                occupancy_range tstzrange GENERATED ALWAYS AS (
                    occupancy_window(appointment_range, buffer_before_minutes, buffer_after_minutes)
                ) STORED NOT NULL,
                status text NOT NULL DEFAULT 'pending_payment'
                    CONSTRAINT appointments_status_check
                    CHECK (status IN ('pending_payment', 'confirmed', 'completed', 'no_show', 'cancelled')),
                cancelled_by text NULL
                    CONSTRAINT appointments_cancelled_by_check CHECK (cancelled_by IN ('customer', 'studio', 'system')),
                cancelled_reason text NULL,
                cancelled_at timestamptz NULL,
                notes text NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT appointments_range_valid CHECK (lower(appointment_range) < upper(appointment_range)),
                CONSTRAINT appointments_cancelled_fields_consistent CHECK ((status = 'cancelled') = (cancelled_by IS NOT NULL)),
                CONSTRAINT appointments_occupancy_contains_appt CHECK (occupancy_range @> appointment_range)
            );

            CREATE INDEX appointments_tenant_staff_starts_idx ON appointments (tenant_id, staff_id, starts_at);
            CREATE INDEX appointments_tenant_customer_idx ON appointments (tenant_id, customer_id);
            CREATE INDEX appointments_appointment_range_gist_idx ON appointments USING gist (appointment_range);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS appointments;');
    }
};
