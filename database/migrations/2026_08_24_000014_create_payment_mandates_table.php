<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// D-0010, amended by D-0022: never modified or deleted by a customer
// erasure — accepted_ip/accepted_user_agent are classified as evidentiary
// (dispute defense), not identifying, and are retained on a legal-claims
// basis. See 04-data-model.md's table notes for the full reasoning; no
// schema mechanism enforces this (it's an application-layer discipline —
// the erasure path simply never touches this table).
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE payment_mandates (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants (id),
                appointment_id uuid NOT NULL REFERENCES appointments (id) ON DELETE RESTRICT,
                mandate_text text NOT NULL,
                mandate_template_version text NOT NULL,
                balance_amount_disclosed integer NOT NULL,
                accepted_at timestamptz NOT NULL,
                accepted_ip inet NOT NULL,
                accepted_user_agent text NULL,
                stripe_payment_intent_id text NOT NULL,
                stripe_payment_method_id text NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now()
            );

            CREATE UNIQUE INDEX payment_mandates_appointment_id_unique ON payment_mandates (appointment_id);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS payment_mandates;');
    }
};
