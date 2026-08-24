<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// NOT tenant-scoped: a webhook may arrive before we know which tenant it
// maps to (04-data-model.md). No RLS on this table.
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE stripe_webhook_events (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                stripe_event_id text NOT NULL,
                type text NOT NULL,
                payload jsonb NOT NULL,
                received_at timestamptz NOT NULL DEFAULT now(),
                processed_at timestamptz NULL,
                processing_error text NULL
            );

            CREATE UNIQUE INDEX stripe_webhook_events_stripe_event_id_unique ON stripe_webhook_events (stripe_event_id);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS stripe_webhook_events;');
    }
};
