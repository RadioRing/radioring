<?php

use App\Support\StaleTransactionGuard;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    // A connection of its own, so the transaction state under test is never the one
    // RefreshDatabase holds open around the test itself.
    config(['database.connections.guard_probe' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]]);

    // Resolves the PDO handle: an unused connection has nothing to inspect.
    DB::connection('guard_probe')->select('select 1');
});

afterEach(function () {
    DB::purge('guard_probe');
});

/**
 * Leaves the connection in exactly the state a deadlocked COMMIT leaves behind: PDO is
 * still in a transaction, Laravel's counter says there is none.
 */
function poisonProbeConnection(): void
{
    DB::connection('guard_probe')->getPdo()->exec('BEGIN');

    expect(DB::connection('guard_probe')->transactionLevel())->toBe(0)
        ->and(DB::connection('guard_probe')->getRawPdo()->inTransaction())->toBeTrue();
}

it('drops a connection whose PDO still holds a forgotten transaction', function () {
    poisonProbeConnection();

    expect(StaleTransactionGuard::reset())->toContain('guard_probe')
        ->and(DB::connection('guard_probe')->getRawPdo())->toBeNull();

    // The replacement handle can open transactions again, which is the whole point.
    $rows = DB::connection('guard_probe')->transaction(
        fn () => DB::connection('guard_probe')->select('select 1 as ok')
    );

    expect($rows[0]->ok)->toBe(1);
});

it('leaves a healthy connection alone', function () {
    $pdo = DB::connection('guard_probe')->getRawPdo();

    expect(StaleTransactionGuard::reset())->toBe([])
        ->and(DB::connection('guard_probe')->getRawPdo())->toBe($pdo);
});

it('leaves a genuinely open transaction alone', function () {
    DB::connection('guard_probe')->beginTransaction();

    $pdo = DB::connection('guard_probe')->getRawPdo();

    expect(StaleTransactionGuard::reset())->toBe([])
        ->and(DB::connection('guard_probe')->getRawPdo())->toBe($pdo)
        ->and(DB::connection('guard_probe')->transactionLevel())->toBe(1);

    DB::connection('guard_probe')->rollBack();
});

it('runs on every turn of the queue worker loop', function () {
    poisonProbeConnection();

    event(new Looping('database', 'default'));

    expect(DB::connection('guard_probe')->getRawPdo())->toBeNull();
});
