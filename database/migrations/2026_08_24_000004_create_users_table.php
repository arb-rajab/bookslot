<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// users is tenant-scoped for role IN ('owner','staff') only — a
// platform_admin row has tenant_id NULL by design (04-data-model.md). RLS
// policy (added in the RLS migration, once this table exists) must allow
// platform_admin rows through regardless of app.current_tenant_id.
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE users (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NULL REFERENCES tenants (id),
                role text NOT NULL
                    CONSTRAINT users_role_check CHECK (role IN ('owner', 'staff', 'platform_admin')),
                name text NOT NULL,
                email text NOT NULL,
                password_hash text NOT NULL,
                email_verified_at timestamptz NULL,
                deleted_at timestamptz NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT users_role_tenant_check CHECK (role = 'platform_admin' OR tenant_id IS NOT NULL)
            );

            CREATE UNIQUE INDEX users_tenant_email_unique ON users (tenant_id, email);
            CREATE UNIQUE INDEX users_platform_admin_email_unique ON users (email) WHERE tenant_id IS NULL;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS users;');
    }
};
