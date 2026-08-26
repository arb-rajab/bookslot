<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// D-0031 (docs/project-memory/09-decision-log.md, amends D-0010): a
// PaymentIntent created with setup_future_usage: off_session has no
// attached payment method until the customer actually enters card details
// (client-side, via Stripe's Payment Element) and the PaymentIntent is
// confirmed — which happens strictly after booking creation returns. The
// column can no longer be populated at mandate-insert time; it's filled in
// later once a payment method is actually known (webhook or
// confirm-payment), not invented as a placeholder here.
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('ALTER TABLE payment_mandates ALTER COLUMN stripe_payment_method_id DROP NOT NULL;');
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE payment_mandates ALTER COLUMN stripe_payment_method_id SET NOT NULL;');
    }
};
