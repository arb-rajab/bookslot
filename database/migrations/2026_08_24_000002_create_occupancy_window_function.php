<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// D-0008 amendment (09-decision-log.md): the direct inline arithmetic form
// of this expression fails at CREATE TABLE time with 42P17 ("generation
// expression is not immutable") — Postgres cannot prove the
// `integer || text` -> `::interval` cast chain is immutable even though it
// is. Wrapping it in an explicitly IMMUTABLE SQL function, with
// `SET search_path = pg_catalog, pg_temp` inline at CREATE FUNCTION time
// (not a later ALTER FUNCTION), is the executed, verified fix — see the
// D-0008 amendment for the full findings (including the search_path
// hijack this hardening closes). Has no table dependency, so it is created
// immediately after the extension and before any business table.
return new class extends Migration
{
    public function up(): void
    {
        // OR REPLACE, not a bare CREATE: Laravel's migrate:fresh drops all
        // tables/views/types but not functions, so this function survives
        // a fresh-migrate cycle in the test database and a bare CREATE
        // would fail with "already exists" on the second run. OR REPLACE
        // keeps this migration safe to run against both a genuinely empty
        // database and one where migrate:fresh just ran — the function
        // body and its inline search_path hardening are unchanged either way.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION occupancy_window(
              appt tstzrange,
              buf_before integer,
              buf_after integer
            ) RETURNS tstzrange
            LANGUAGE sql
            IMMUTABLE PARALLEL SAFE
            SET search_path = pg_catalog, pg_temp
            AS $$
              SELECT tstzrange(
                lower(appt) - make_interval(mins => buf_before),
                upper(appt) + make_interval(mins => buf_after),
                '[)'
              )
            $$;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS occupancy_window(tstzrange, integer, integer);');
    }
};
