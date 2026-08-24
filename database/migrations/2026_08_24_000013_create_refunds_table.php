<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE refunds (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants (id),
                payment_id uuid NOT NULL REFERENCES payments (id) ON DELETE RESTRICT,
                stripe_refund_id text NOT NULL,
                amount integer NOT NULL,
                reason text NULL,
                status text NOT NULL
                    CONSTRAINT refunds_status_check CHECK (status IN ('pending', 'succeeded', 'failed')),
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now()
            );

            CREATE UNIQUE INDEX refunds_stripe_refund_id_unique ON refunds (stripe_refund_id);
            CREATE INDEX refunds_tenant_payment_idx ON refunds (tenant_id, payment_id);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS refunds;');
    }
};
