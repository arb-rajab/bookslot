<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// D-0007 (docs/project-memory/09-decision-log.md): btree_gist is what lets
// the appointments exclusion constraint use GIST equality comparisons on
// scalar columns (tenant_id, staff_id). Must run before anything that adds
// that constraint. Verified (08-deployment-and-operations.md) as a
// "trusted" extension installable by a role with plain CREATE privilege,
// not superuser — bookslot_migrator does not need elevated rights for this.
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('CREATE EXTENSION IF NOT EXISTS btree_gist;');
    }

    public function down(): void
    {
        DB::unprepared('DROP EXTENSION IF EXISTS btree_gist;');
    }
};
