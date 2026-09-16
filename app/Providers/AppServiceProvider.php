<?php

namespace App\Providers;

use App\Payments\ConnectOnboardingGateway;
use App\Payments\FakeConnectOnboardingGateway;
use App\Payments\FakePaymentIntentGateway;
use App\Payments\PaymentIntentGateway;
use App\Payments\StripeConnectOnboardingGateway;
use App\Payments\StripePaymentIntentGateway;
use App\Queue\RabbitMq\RabbitMqConnector;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Stripe\StripeClient;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // D-0027/D-0030: bound as an interface, not instantiated inline in
        // BookingController, so tests can swap in a fake that delays or
        // throws on demand (07-testing-strategy.md's "faked Stripe client
        // by default" tier) without touching real Stripe infrastructure.
        $this->app->singleton(StripeClient::class, fn () => new StripeClient(config('services.stripe.secret_key')));

        // D-0038: D-0036 permanently descoped real Stripe credentials for
        // this project, but every prior session left this runtime binding
        // hard-wired to the real gateway regardless — meaning the app
        // itself, not just its test suite, would throw on the first real
        // booking request against `sk_test_PLACEHOLDER_NOT_REAL`. Falls
        // back to the fake tier whenever no real-looking secret key is
        // configured, so a real HTTP request through this app's own routes
        // exercises real code end to end, matching whichever gateway tier
        // is actually available rather than assuming real Stripe exists.
        $secretKey = config('services.stripe.secret_key');
        $looksReal = is_string($secretKey) && str_starts_with($secretKey, 'sk_')
            && ! str_contains($secretKey, 'PLACEHOLDER');

        if ($looksReal) {
            $this->app->bind(PaymentIntentGateway::class, StripePaymentIntentGateway::class);
            $this->app->bind(ConnectOnboardingGateway::class, StripeConnectOnboardingGateway::class);
        } else {
            $this->app->bind(PaymentIntentGateway::class, FakePaymentIntentGateway::class);
            $this->app->bind(ConnectOnboardingGateway::class, FakeConnectOnboardingGateway::class);
            Log::warning('No real Stripe secret key configured (D-0036/D-0038) — PaymentIntentGateway/ConnectOnboardingGateway are bound to the fake tier.');
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // D-0047: registers the 'rabbitmq' queue connection's driver.
        // Laravel resolves a connector by the connection's own 'driver' key
        // (config/queue.php), never by the connection name itself, so this
        // extend() call is what makes QUEUE_CONNECTION=rabbitmq resolvable
        // at all.
        Queue::extend('rabbitmq', fn () => new RabbitMqConnector);
    }
}
