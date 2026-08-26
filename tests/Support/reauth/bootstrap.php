<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Symfony\Component\HttpFoundation\Response;

/**
 * Same standalone-real-OS-process approach as
 * tests/Support/concurrency/bootstrap.php (see its docblock) — used here for
 * D-0043's regression case instead of D-0007's race case. The one difference
 * that matters: SESSION_DRIVER is forced to 'file' here, overriding
 * .env.testing's deliberate 'array' default. 'array' is process-local memory
 * that dies with the process — exactly right for every other Pest test
 * (.env.testing's own comment explains why), and exactly wrong here, since a
 * session written by one real OS process (login) must be readable by a
 * second, genuinely separate one (the actual re-authentication attempt) the
 * same way it would be against a real running `php artisan serve`. 'file' is
 * the smallest change that survives a process boundary without adding a
 * Redis dependency to the fast gate.
 */
function bootReauthProbeApp(): Application
{
    // putenv() alone is not enough: this script is itself spawned as a
    // child of Pest's own PHP process, which has already loaded
    // .env.testing's SESSION_DRIVER=array into ITS OWN $_SERVER/$_ENV — and
    // those get inherited into this child's $_SERVER/$_ENV superglobals at
    // process startup, before this file even runs. Dotenv's immutable
    // repository checks $_SERVER/$_ENV, not just getenv(), so a bare
    // putenv() here silently loses to the inherited $_SERVER value (this
    // was confirmed by execution: a standalone `php login_probe.php` run —
    // no Pest parent, nothing to inherit — worked with putenv() alone,
    // while the exact same script under Pest kept resolving
    // config('session.driver') to 'array'). All three writers must be set
    // for the override to actually stick in every codepath Dotenv reads.
    foreach ([['APP_ENV', 'testing'], ['SESSION_DRIVER', 'file']] as [$key, $value]) {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    require __DIR__.'/../../../vendor/autoload.php';

    /** @var Application $app */
    $app = require __DIR__.'/../../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    return $app;
}

/**
 * @return array{0: ?string, 1: ?string} [session cookie value, XSRF-TOKEN
 *                                       cookie value — both still in their raw, encrypted-as-sent-over-the-wire
 *                                       form, exactly as a browser would hold and replay them]
 */
function extractReauthCookies(Response $response, string $sessionCookieName): array
{
    $session = null;
    $xsrf = null;

    foreach ($response->headers->getCookies() as $cookie) {
        if ($cookie->getName() === $sessionCookieName) {
            $session = $cookie->getValue();
        } elseif ($cookie->getName() === 'XSRF-TOKEN') {
            $xsrf = $cookie->getValue();
        }
    }

    return [$session, $xsrf];
}
