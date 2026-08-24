<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// D-0005/D-0009: the Postgres RLS backstop. Runs after every tenant-scoped
// table exists. Driven by config('tenancy.tenant_scoped_tables') — the same
// manifest the tenant-isolation test suite's manifest-completeness and
// RLS-enforcement checks are graded against — so this migration and those
// tests can never silently drift apart over which tables are covered.
//
// `users` gets a widened policy (platform_admin rows visible regardless of
// the current tenant GUC), per 04-data-model.md's tenancy-boundary note and
// D-0009's platform-admin impersonation path. Every other table gets the
// standard policy from 04-data-model.md — amended, see below.
//
// Amendment (this session, execution-tested): 04's literal DDL —
// `current_setting(guc, true)::uuid` — is correct only for a connection
// that has NEVER called set_config() for this GUC. Once any transaction on
// a physical connection has set it, Postgres permanently "defines" the
// custom GUC for that connection's remaining lifetime: current_setting
// then returns an EMPTY STRING, not NULL, whenever no context is active —
// even after a real COMMIT, even for a later, unrelated transaction. Under
// this project's own chosen PgBouncer transaction-mode pooling
// (08-deployment-and-operations.md), connections are deliberately reused
// across requests, so this is the normal state after the first
// tenant-scoped request on any given backend connection, not an edge case.
// `''::uuid` raises SQLSTATE 22P02 rather than comparing as NULL, so a
// future missing-context bug on a reused connection would surface as a
// thrown exception instead of 04's documented zero-rows result. Wrapping
// the value in NULLIF(..., '') restores the documented behavior for real:
// an empty string is treated exactly like NULL, so the comparison is NULL
// (no match, fail closed) rather than a cast error. Verified directly
// against Postgres 17.11 before and after this change. See 09's D-0025.
return new class extends Migration
{
    public function up(): void
    {
        $guc = config('tenancy.guc');

        foreach (config('tenancy.tenant_scoped_tables') as $table) {
            DB::unprepared("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY;");
            DB::unprepared("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY;");

            if ($table === 'users') {
                DB::unprepared(<<<SQL
                    CREATE POLICY tenant_isolation ON users
                      USING (
                        role = 'platform_admin'
                        OR tenant_id = NULLIF(current_setting('{$guc}', true), '')::uuid
                      );
                SQL);

                continue;
            }

            DB::unprepared(<<<SQL
                CREATE POLICY tenant_isolation ON {$table}
                  USING (tenant_id = NULLIF(current_setting('{$guc}', true), '')::uuid);
            SQL);
        }
    }

    public function down(): void
    {
        foreach (config('tenancy.tenant_scoped_tables') as $table) {
            DB::unprepared("DROP POLICY IF EXISTS tenant_isolation ON {$table};");
            DB::unprepared("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY;");
            DB::unprepared("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY;");
        }
    }
};
