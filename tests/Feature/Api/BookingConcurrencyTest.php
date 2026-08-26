<?php

/**
 * D-0007's required concurrency case, finally buildable now that booking
 * creation exists (07-testing-strategy.md's "Concurrency and slot
 * integrity" section, flagged as uncovered by every session since
 * Session 8): two simultaneous booking attempts on the exact same slot —
 * one 201, one clean 409, never a 500, never both 201.
 *
 * A single PHP test process cannot produce two genuinely simultaneous,
 * separately-connected database transactions on its own — Pest's
 * RefreshDatabase also wraps this test in one outer transaction that a
 * second connection couldn't see anyway. So this test spawns two REAL,
 * separate OS processes (tests/Support/concurrency/probe.php), each
 * bootstrapping its own Laravel application and its own database
 * connection, each dispatching one real HTTP-level request through
 * BookingController — synchronized to fire at the same target
 * microtime so the race is real, not just plausible. Fixture data is
 * created and committed by a third standalone process
 * (tests/Support/concurrency/setup.php) for the same reason: this test's
 * own RefreshDatabase transaction is invisible to the other two
 * processes' connections.
 *
 * This test's own Laravel app/database connection (via Tests\TestCase,
 * bound for every file under tests/Feature) is otherwise unused here — it
 * only orchestrates three separate child processes and inspects their
 * output; Pest's own RefreshDatabase-wrapped transaction on this
 * connection never writes anything and has no effect on the children's
 * entirely separate connections.
 */
function runProbeProcess(string $slug, string $serviceId, string $staffId, string $startsAt, string $email, float $targetMicrotime): array
{
    $php = PHP_BINARY;
    $script = __DIR__.'/../../Support/concurrency/probe.php';

    $process = proc_open(
        [$php, $script, $slug, $serviceId, $staffId, $startsAt, $email, (string) $targetMicrotime],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__, 3),
    );

    return ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
}

test('two simultaneous booking attempts on the exact same slot: one 201, one clean 409', function () {
    $setup = json_decode(
        shell_exec(sprintf('%s %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg(__DIR__.'/../../Support/concurrency/setup.php'))),
        true,
    );

    expect($setup)->toBeArray();
    expect($setup['slug'] ?? null)->toBeString();

    $startsAt = (new DateTime('+3 days'))->setTime(17, 0)->format(DATE_ATOM);
    $target = microtime(true) + 1.0;

    $probeA = runProbeProcess($setup['slug'], $setup['service_id'], $setup['staff_id'], $startsAt, 'race-a@example.test', $target);
    $probeB = runProbeProcess($setup['slug'], $setup['service_id'], $setup['staff_id'], $startsAt, 'race-b@example.test', $target);

    $outputA = stream_get_contents($probeA['stdout']);
    $errA = stream_get_contents($probeA['stderr']);
    $outputB = stream_get_contents($probeB['stdout']);
    $errB = stream_get_contents($probeB['stderr']);

    foreach ([$probeA, $probeB] as $probe) {
        fclose($probe['stdout']);
        fclose($probe['stderr']);
        proc_close($probe['process']);
    }

    [$statusA] = explode('|', $outputA, 2) + [null];
    [$statusB] = explode('|', $outputB, 2) + [null];

    $statuses = [(int) $statusA, (int) $statusB];
    sort($statuses);

    expect($errA)->toBe('', "probe A stderr: {$errA}\nstdout: {$outputA}");
    expect($errB)->toBe('', "probe B stderr: {$errB}\nstdout: {$outputB}");
    expect($statuses)->toBe([201, 409], "A={$outputA}\nB={$outputB}");
});
