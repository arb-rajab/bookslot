<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// D-0065 (docs/project-memory/09-decision-log.md): `deauthorized` is a new,
// distinct value from `restricted` — `restricted` still means "Stripe has
// flagged this same, still-connected account" (a fresh Account Link can fix
// it), while `deauthorized` means the platform's access to that account was
// fully revoked and `stripe_connect_account_id` has been cleared, so the
// owner must connect a brand new account. Collapsing the two into one
// `restricted` value (D-0058's original design) is exactly what made
// reconnection undiscoverable to the owner-admin UI. Same
// drop-and-recreate-CHECK-constraint pattern as
// 2026_08_25_000019_add_platform_admin_to_booking_events_actor_type.php.
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE tenants DROP CONSTRAINT tenants_stripe_onboarding_status_check;
            ALTER TABLE tenants
                ADD CONSTRAINT tenants_stripe_onboarding_status_check
                CHECK (stripe_onboarding_status IN ('not_started', 'pending', 'complete', 'restricted', 'deauthorized'));
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE tenants DROP CONSTRAINT tenants_stripe_onboarding_status_check;
            ALTER TABLE tenants
                ADD CONSTRAINT tenants_stripe_onboarding_status_check
                CHECK (stripe_onboarding_status IN ('not_started', 'pending', 'complete', 'restricted'));
        SQL);
    }
};
