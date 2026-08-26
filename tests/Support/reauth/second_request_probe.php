<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

require __DIR__.'/bootstrap.php';

/** @var array<int, string> $arguments */
$arguments = $_SERVER['argv'];
[, $sessionCookieName, $sessionValue, $xsrfValue] = $arguments;

// A BRAND NEW app instance in a BRAND NEW OS process — its own, entirely
// fresh database connection that no TenantContext::run() call (fixture setup
// or otherwise) has ever touched. This is the exact condition D-0043's root
// cause needed to manifest: the auth guard's own user-lookup query being the
// FIRST query this connection ever runs, with no tenant GUC set by anything
// earlier on the same connection. Pest's shared in-process connection can
// never reproduce this (see this test's own file docblock).
$app = bootReauthProbeApp();
$kernel = $app->make(Kernel::class);

$request = Request::create(
    '/api/owner/appointments',
    'GET',
    [],
    [$sessionCookieName => $sessionValue, 'XSRF-TOKEN' => $xsrfValue],
    [],
    [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_ORIGIN' => 'http://localhost',
    ],
);

$response = $kernel->handle($request);
$kernel->terminate($request, $response);

echo $response->getStatusCode().'|'.$response->getContent();
