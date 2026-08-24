<?php

use Illuminate\Support\Facades\DB;

/**
 * D-0009/D-0020: bookslot_migrator (BYPASSRLS) is used only for migrations,
 * seeders, backfills, and pg_dump/pg_restore — never by a running request
 * or queue worker. Checks both halves of that claim directly: the app's
 * configured runtime credentials aren't the migrator's, and a live
 * connection using those runtime credentials confirms BYPASSRLS is false
 * for the role actually in use.
 */
test('the application\'s runtime database credentials are not the migrator\'s', function () {
    $runtimeUsername = config('database.connections.'.config('database.default').'.username');
    $migratorUsername = config('database.connections.pgsql_migrator.username');

    expect($runtimeUsername)->not->toBeNull();
    expect($runtimeUsername)->not->toBe($migratorUsername);
    expect($runtimeUsername)->toBe('bookslot_app');
    expect($migratorUsername)->toBe('bookslot_migrator');
});

test('the runtime connection\'s actual database role does not have BYPASSRLS', function () {
    $row = DB::selectOne('select rolbypassrls from pg_roles where rolname = current_user');

    expect((bool) $row->rolbypassrls)->toBeFalse(
        'The application runtime connection is using a role with BYPASSRLS — this must never be true outside bookslot_migrator\'s offline use.'
    );
});

test('the migrator connection is a genuinely different role that does hold BYPASSRLS', function () {
    // Confirms the test above is actually discriminating between the two
    // roles, not passing because neither role has BYPASSRLS for some
    // unrelated reason (e.g. a misconfigured local Postgres).
    $row = DB::connection('pgsql_migrator')->selectOne('select current_user as role, rolbypassrls from pg_roles where rolname = current_user');

    expect($row->role)->toBe('bookslot_migrator');
    expect((bool) $row->rolbypassrls)->toBeTrue();
});
