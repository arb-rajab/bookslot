<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Payment status includes 'paid_manually' — a reachable terminal state for
// a balance payment settled outside Stripe (J6), per 04-data-model.md's
// payment state machine note.
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE payments (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants (id),
                appointment_id uuid NOT NULL REFERENCES appointments (id) ON DELETE RESTRICT,
                type text NOT NULL
                    CONSTRAINT payments_type_check CHECK (type IN ('deposit', 'balance')),
                stripe_payment_intent_id text NOT NULL,
                stripe_charge_id text NULL,
                amount integer NOT NULL,
                currency char(3) NOT NULL,
                application_fee_amount integer NULL,
                status text NOT NULL
                    CONSTRAINT payments_status_check CHECK (status IN (
                        'requires_action', 'processing', 'succeeded', 'failed',
                        'refunded', 'partially_refunded', 'paid_manually'
                    )),
                failure_code text NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now()
            );

            CREATE UNIQUE INDEX payments_stripe_payment_intent_id_unique ON payments (stripe_payment_intent_id);
            CREATE INDEX payments_tenant_appointment_type_idx ON payments (tenant_id, appointment_id, type);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS payments;');
    }
};
