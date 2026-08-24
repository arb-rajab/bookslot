<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// tenants is the tenancy boundary itself, not a tenant-scoped table — see
// 04-data-model.md's "Tenancy boundary" section. No RLS here.
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE tenants (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                name text NOT NULL,
                slug text NOT NULL,
                timezone text NOT NULL,
                currency char(3) NOT NULL,
                stripe_connect_account_id text NULL,
                stripe_onboarding_status text NOT NULL DEFAULT 'not_started'
                    CONSTRAINT tenants_stripe_onboarding_status_check
                    CHECK (stripe_onboarding_status IN ('not_started', 'pending', 'complete', 'restricted')),
                deleted_at timestamptz NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT tenants_slug_unique UNIQUE (slug)
            );

            CREATE INDEX tenants_active_idx ON tenants (slug) WHERE deleted_at IS NULL;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS tenants;');
    }
};
