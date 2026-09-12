<?php

namespace App\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDO;
use Throwable;

/**
 * Drops database connections whose PDO handle still holds a transaction that Laravel
 * has already forgotten about.
 *
 * How the two get out of step: when a COMMIT fails with a deadlock (MySQL 1213) on the
 * last attempt of a transaction, `Connection::handleCommitTransactionException()` lowers
 * the transaction counter to zero and rethrows, but only issues a ROLLBACK when another
 * attempt follows. PDO therefore keeps its "in transaction" flag while Laravel believes
 * there is no transaction, and every later `beginTransaction()` on that connection dies
 * with "There is already an active transaction".
 *
 * In a web request that is a single failed response. In a queue worker it is permanent:
 * the process keeps the poisoned connection, `DatabaseQueue::pop()` fails on every
 * iteration, and no job runs again until the worker is restarted. Dropping the
 * connection costs a reconnect and ends the whole episode.
 */
class StaleTransactionGuard
{
    /**
     * Disconnects every resolved connection that is out of step, and returns their names.
     *
     * @return list<string>
     */
    public static function reset(): array
    {
        $reset = [];

        foreach (DB::getConnections() as $name => $connection) {
            if (! self::isStale($connection)) {
                continue;
            }

            $connection->disconnect();
            $reset[] = (string) $name;

            Log::warning('Database connection dropped: PDO still held a transaction Laravel had already given up on.', [
                'connection' => $name,
            ]);
        }

        return $reset;
    }

    /**
     * Only already resolved handles are inspected. `getRawPdo()` returns the lazy
     * resolver closure or null while the connection has never been used, and asking for
     * the real handle there would open a connection just to check on it.
     */
    private static function isStale(Connection $connection): bool
    {
        if ($connection->transactionLevel() !== 0) {
            return false;
        }

        $pdo = $connection->getRawPdo();

        if (! $pdo instanceof PDO) {
            return false;
        }

        try {
            return $pdo->inTransaction();
        } catch (Throwable) {
            // A driver that cannot answer the question is not evidence of a problem.
            return false;
        }
    }
}
