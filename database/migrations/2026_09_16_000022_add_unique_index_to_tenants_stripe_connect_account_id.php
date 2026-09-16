<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// D-0058: two tenants must never end up linked to the same Stripe Connect
// account id — a partial unique index (NULL-safe: most tenants have no
// account yet) is what actually enforces that at the database layer,
// rather than relying on application code never producing a collision.
// Also the index the Connect webhook handler's account-id lookup
// (account.application.deauthorized, whose payload carries no
// metadata.tenant_id) depends on for correctness and speed.
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX tenants_stripe_connect_account_id_unique
                ON tenants (stripe_connect_account_id)
                WHERE stripe_connect_account_id IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP INDEX IF EXISTS tenants_stripe_connect_account_id_unique;');
    }
};
