<?php

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\Queue\FailsOnceThenSucceedsJob;
use Tests\Support\Queue\WritesMarkerJob;

/**
 * D-0047 (docs/project-memory/09-decision-log.md): the one suite in this
 * project that talks to a real broker rather than a fake/sync stand-in —
 * `queue-broker` Pest group, excluded from `composer test:fast`
 * (composer.json), run via `composer test:queue-broker` wherever a real
 * RabbitMQ is reachable (docker-compose's `rabbitmq` service locally, a
 * `rabbitmq` CI service container). Publishing happens in-process against
 * the real broker (RABBITMQ_HOST/.env.testing); consuming runs a real,
 * separate `php artisan queue:work rabbitmq --once` subprocess each time —
 * the same "prove it against a genuinely separate process" standard
 * BookingConcurrencyTest already set for this codebase, here proving
 * App\Queue\RabbitMq's connector/queue/job classes actually work end to
 * end against Laravel's own Worker, not just against assumptions about
 * what CallQueuedHandler does with them.
 *
 * Every test gets its own randomly-suffixed queue name so a message or
 * dead-letter left behind by a failed run of this suite can never leak
 * into the next run or another test.
 */
function runQueueWorkerOnce(string $queue, int $timeoutSeconds = 15): \Illuminate\Contracts\Process\ProcessResult
{
    return Process::path(base_path())
        ->env(['APP_ENV' => 'testing'])
        ->timeout($timeoutSeconds)
        ->run(['php', 'artisan', 'queue:work', 'rabbitmq', '--queue='.$queue, '--once', '--sleep=0']);
}

test('a job published to the real rabbitmq connection is consumed by a real queue:work process', function () {
    $queue = 'bookslot_test_'.Str::random(12);
    $markerPath = tempnam(sys_get_temp_dir(), 'bookslot_queue_marker_');

    Queue::connection('rabbitmq')->pushOn($queue, new WritesMarkerJob($markerPath, 'delivered'));

    $result = runQueueWorkerOnce($queue);

    expect($result->successful())->toBeTrue($result->errorOutput());
    expect(file_exists($markerPath))->toBeTrue();
    expect(file_get_contents($markerPath))->toBe('delivered');

    @unlink($markerPath);
})->group('queue-broker');

test('a delayed job is not delivered before its TTL elapses, and is delivered once it does — the DLX delay pattern, for real', function () {
    $queue = 'bookslot_test_'.Str::random(12);
    $markerPath = tempnam(sys_get_temp_dir(), 'bookslot_queue_marker_');
    unlink($markerPath); // pushOn/later must create the file itself once delivered — prove absence first.

    Queue::connection('rabbitmq')->laterOn($queue, 3, new WritesMarkerJob($markerPath, 'delayed-delivered'));

    // Immediately: the message sits in `{queue}.delay`, not yet dead-lettered
    // back to the real queue — a worker run right now must find nothing.
    $tooEarly = runQueueWorkerOnce($queue, timeoutSeconds: 5);
    expect(file_exists($markerPath))->toBeFalse();

    sleep(4); // past the 3-second TTL

    $onTime = runQueueWorkerOnce($queue);
    expect($onTime->successful())->toBeTrue($onTime->errorOutput());
    expect(file_exists($markerPath))->toBeTrue();
    expect(file_get_contents($markerPath))->toBe('delayed-delivered');

    @unlink($markerPath);
})->group('queue-broker');

test('a job that fails its first attempt is retried and succeeds on the second — real release()/attempts() round-tripped through RabbitMQ', function () {
    $queue = 'bookslot_test_'.Str::random(12);
    $markerPath = tempnam(sys_get_temp_dir(), 'bookslot_queue_marker_');
    unlink($markerPath);

    Queue::connection('rabbitmq')->pushOn($queue, new FailsOnceThenSucceedsJob($markerPath));

    // Attempt 1: throws, gets release()'d (backoff=0) — the worker's
    // --once flag means this invocation stops after that one attempt,
    // before ever seeing the re-queued message.
    $firstRun = runQueueWorkerOnce($queue);
    expect($firstRun->successful())->toBeTrue($firstRun->errorOutput());
    expect(file_exists($markerPath))->toBeFalse();

    // Attempt 2: the SAME logical job, now carrying attempts() === 2 via
    // the real x-bookslot-attempt header RabbitMqJob::release() set and
    // RabbitMqQueue republished — this is the assertion that actually
    // proves retry tracking survives a real broker round-trip, not just an
    // in-memory one.
    $secondRun = runQueueWorkerOnce($queue);
    expect($secondRun->successful())->toBeTrue($secondRun->errorOutput());
    expect(file_exists($markerPath))->toBeTrue();
    expect(file_get_contents($markerPath))->toBe('succeeded on attempt 2');

    @unlink($markerPath);
})->group('queue-broker');
