<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

/**
 * Shared bootstrap for the standalone CLI probe scripts used by
 * BookingConcurrencyTest.php. These run as real, separate OS processes
 * (not inside Pest's RefreshDatabase-wrapped test transaction) — that's
 * the entire point: proving D-0007's "two simultaneous booking attempts,
 * one 201/one 409" guarantee needs two genuinely separate database
 * connections racing against the real HTTP-level BookingController, which
 * a single PHP test process cannot produce on its own.
 */
function bootProbeApp(): Application
{
    putenv('APP_ENV=testing');

    require __DIR__.'/../../../vendor/autoload.php';

    /** @var Application $app */
    $app = require __DIR__.'/../../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    return $app;
}
