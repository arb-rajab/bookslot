<?php

use App\Payments\PaymentIntentGateway;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Tests\Support\FakePaymentIntentGateway;

require __DIR__.'/bootstrap.php';

/** @var array<int, string> $arguments */
$arguments = $_SERVER['argv'];
[, $slug, $serviceId, $staffId, $startsAt, $customerEmail, $targetMicrotime] = $arguments;

$app = bootProbeApp();

$app->bind(PaymentIntentGateway::class, fn () => new FakePaymentIntentGateway);

if ($targetMicrotime !== '') {
    $delay = ((float) $targetMicrotime) - microtime(true);
    if ($delay > 0) {
        usleep((int) ($delay * 1_000_000));
    }
}

$request = Request::create(
    "/api/tenants/{$slug}/bookings",
    'POST',
    [],
    [],
    [],
    ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
    json_encode([
        'service_id' => $serviceId,
        'staff_id' => $staffId,
        'starts_at' => $startsAt,
        'customer' => ['name' => 'Race Customer', 'email' => $customerEmail],
        'mandate_accepted' => true,
        'mandate_template_version' => 'v1',
    ], JSON_THROW_ON_ERROR),
);

$kernel = $app->make(Kernel::class);
$response = $kernel->handle($request);

echo $response->getStatusCode().'|'.$response->getContent();
