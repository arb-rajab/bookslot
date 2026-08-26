<?php

/**
 * D-0043 regression guard (docs/project-memory/09-decision-log.md;
 * 10-risk-register.md's R-08 cites this exact bug as proof the risk it
 * describes is not theoretical).
 *
 * Every existing test that exercises "login, then a subsequent
 * owner/staff-authenticated request" (AuthControllerTest,
 * OwnerAppointmentControllerTest, StaffAppointmentControllerTest) does so
 * within one Pest test method — one shared in-process HTTP client, one
 * shared database connection/transaction. That is precisely the condition
 * D-0043's own root-cause writeup names as what let the bug ship and survive
 * six sessions undetected: D-0025's already-documented mechanism means any
 * earlier TenantContext::run() call on that connection (even just this
 * test's own tenant/owner fixture setup) leaves the DB-level tenant GUC set
 * for the rest of the outer test transaction, so the auth guard's
 * user-lookup query — the thing that actually broke — never runs against a
 * genuinely GUC-less connection inside Pest. D-0043 itself was only ever
 * found by driving a real login against a live `php artisan serve` process,
 * then a second, genuinely separate `curl` request reusing the session
 * cookie.
 *
 * SequentialRequestTenantContextTest.php already covers cross-request
 * GUC/RLS leakage at the connection level, but it stays entirely inside one
 * Pest test method on one shared connection — it could not have caught
 * D-0043, because D-0043's break condition is "this is the first query this
 * connection has ever run," which a shared connection can never be.
 *
 * This test reproduces the real condition instead: three genuinely separate
 * `php` OS processes, each its own fresh app instance and fresh database
 * connection, orchestrated the same way tests/Feature/Api/
 * BookingConcurrencyTest.php already does for D-0007's race case
 * (tests/Support/concurrency/) — extended here with a persistent ('file')
 * session driver, since .env.testing's array driver dies with the process
 * (see tests/Support/reauth/bootstrap.php's docblock for why that's the one
 * thing that has to change).
 *
 *   1. tests/Support/reauth/setup.php — creates and commits a real tenant +
 *      owner, outside any Pest transaction.
 *   2. tests/Support/reauth/login_probe.php — the real GET
 *      /sanctum/csrf-cookie -> POST login round trip; captures the
 *      post-login (post session-regenerate) session + XSRF cookies.
 *   3. tests/Support/reauth/second_request_probe.php — a BRAND NEW process
 *      presenting only the cookies step 2 captured, exactly as a real
 *      returning browser session would on its next visit.
 *
 * Before D-0043's fix, step 3 returned 401 here. This test asserts it must
 * return 200 — if the owner/staff middleware ordering ever regresses to
 * something equivalent, this is the test that catches it, not another
 * green Pest run that never left the shared connection.
 */
function runReauthProbe(string $script, array $args): array
{
    $php = PHP_BINARY;

    $process = proc_open(
        [$php, __DIR__.'/../../Support/reauth/'.$script, ...$args],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__, 3),
    );

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return [$stdout, $stderr];
}

test('a returning owner session, presented on a genuinely separate second HTTP request/process/connection, re-authenticates', function () {
    [$setupOut, $setupErr] = runReauthProbe('setup.php', []);
    expect($setupErr)->toBe('', "setup probe stderr: {$setupErr}\nstdout: {$setupOut}");

    $setup = json_decode($setupOut, true);
    expect($setup['slug'] ?? null)->toBeString();

    [$loginOut, $loginErr] = runReauthProbe('login_probe.php', [
        $setup['slug'], 'owner@example.test', 'correct-password',
    ]);
    expect($loginErr)->toBe('', "login probe stderr: {$loginErr}\nstdout: {$loginOut}");

    $login = json_decode($loginOut, true);
    expect($login['login_status'] ?? null)->toBe(200, "login probe's own login did not succeed: {$loginOut}");
    expect($login['session_value'] ?? null)->toBeString();
    expect($login['xsrf_value'] ?? null)->toBeString();

    [$secondOut, $secondErr] = runReauthProbe('second_request_probe.php', [
        $login['session_cookie_name'], $login['session_value'], $login['xsrf_value'],
    ]);
    expect($secondErr)->toBe('', "second-request probe stderr: {$secondErr}\nstdout: {$secondOut}");

    [$status] = explode('|', $secondOut, 2) + [null];

    expect((int) $status)->toBe(
        200,
        "a second, genuinely separate process/connection failed to re-authenticate a returning owner session (D-0043 regression): {$secondOut}",
    );
});
