<?php

namespace App\Services\Backup;

use Generator;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

/**
 * Dumps and restores the application database without any external binary.
 *
 * `mysqldump` is deliberately not used: it is not part of the runtime image, and an
 * installation that cannot restore its own backup because a client tool is missing is
 * worse than no backup at all. Everything here goes through PDO, so it works the same on
 * SQLite (the default for small installations) and on MySQL.
 *
 * The dump is JSON Lines, not SQL. Every line is one self-contained record, which removes
 * the whole class of restore bugs that comes from splitting an SQL file on semicolons that
 * also occur inside values. Table definitions are still the native `CREATE TABLE` of the
 * source database, so a dump is bound to its driver; restoring across drivers is refused
 * rather than half-attempted.
 */
class DatabaseDumper
{
    public const FORMAT_VERSION = 1;

    /**
     * Rows of these tables are worthless the moment the dump is written: caches, sessions
     * and queued jobs describe a running instance, not its content. Their structure is
     * still dumped, only the contents are skipped.
     *
     * @var list<string>
     */
    private const TRANSIENT_TABLES = [
        'cache',
        'cache_locks',
        'sessions',
        'jobs',
        'job_batches',
        'failed_jobs',
        'password_reset_tokens',
        // The backup history points at archives on this host's disk. Restoring it onto
        // another machine would list downloads that do not exist there.
        'backups',
    ];

    /**
     * Rows per `rows` record. Keeps peak memory flat on large tables such as station_logs.
     */
    private const CHUNK_SIZE = 500;

    public function driver(): string
    {
        return DB::connection()->getDriverName();
    }

    /**
     * Writes the whole database to the given absolute path.
     */
    public function dump(string $targetPath): void
    {
        $handle = fopen($targetPath, 'wb');

        if ($handle === false) {
            throw new RuntimeException("Cannot write database dump to {$targetPath}.");
        }

        try {
            foreach ($this->records() as $record) {
                $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                fwrite($handle, $line."\n");
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Replaces the current database with the contents of a dump.
     *
     * Foreign keys are switched off for the duration, so table order does not matter.
     * MySQL commits DDL implicitly, so a dump that turns out to be broken half-way can
     * leave the structure partially replaced. That is why the restore command works on a
     * validated archive and asks before it starts.
     */
    public function restore(string $sourcePath): void
    {
        // Checked before a single table is dropped: an incompatible dump must not cost
        // the operator the database they still had.
        $this->assertCompatible($this->readMeta($sourcePath));

        $handle = fopen($sourcePath, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Cannot read database dump from {$sourcePath}.");
        }

        $connection = DB::connection();

        try {
            $this->withoutForeignKeyChecks(function () use ($handle, $connection) {
                $this->dropEverything();

                $lineNumber = 0;

                while (($line = fgets($handle)) !== false) {
                    $lineNumber++;
                    $line = trim($line);

                    if ($line === '') {
                        continue;
                    }

                    $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

                    match ($record['t'] ?? null) {
                        'meta' => $this->assertCompatible($record),
                        'schema' => $connection->getPdo()->exec($record['sql']),
                        'rows' => $this->insertRows($connection, $record),
                        default => throw new RuntimeException("Unknown record type on line {$lineNumber}."),
                    };
                }
            });
        } finally {
            fclose($handle);
        }
    }

    /**
     * Reads only the leading meta record, to check a dump before anything is touched.
     *
     * @return array<string, mixed>
     */
    public function readMeta(string $sourcePath): array
    {
        $handle = fopen($sourcePath, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Cannot read database dump from {$sourcePath}.");
        }

        $line = fgets($handle);
        fclose($handle);

        if ($line === false) {
            throw new RuntimeException('The database dump is empty.');
        }

        $meta = json_decode(trim($line), true, flags: JSON_THROW_ON_ERROR);

        if (($meta['t'] ?? null) !== 'meta') {
            throw new RuntimeException('The database dump does not start with a meta record.');
        }

        return $meta;
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function records(): Generator
    {
        yield [
            't' => 'meta',
            'format' => self::FORMAT_VERSION,
            'driver' => $this->driver(),
            'database' => DB::connection()->getDatabaseName(),
            'generated_at' => now()->toIso8601String(),
        ];

        $definitions = $this->tableDefinitions();

        foreach ($definitions as $table => $statements) {
            foreach ($statements as $sql) {
                yield ['t' => 'schema', 'table' => $table, 'sql' => $sql];
            }
        }

        foreach (array_keys($definitions) as $table) {
            if (in_array($table, self::TRANSIENT_TABLES, true)) {
                continue;
            }

            yield from $this->tableRows($table);
        }
    }

    /**
     * Table name to the DDL statements that recreate it, indexes and triggers included.
     *
     * @return array<string, list<string>>
     */
    private function tableDefinitions(): array
    {
        return $this->driver() === 'sqlite'
            ? $this->sqliteDefinitions()
            : $this->mysqlDefinitions();
    }

    /**
     * @return array<string, list<string>>
     */
    private function sqliteDefinitions(): array
    {
        $rows = DB::select(
            "SELECT name, tbl_name, type, sql FROM sqlite_master
             WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%'
             ORDER BY CASE type WHEN 'table' THEN 0 WHEN 'view' THEN 1 ELSE 2 END, name"
        );

        $definitions = [];

        foreach ($rows as $row) {
            // Indexes and triggers are grouped under their table, so they are recreated
            // right after it and never end up orphaned.
            $definitions[$row->tbl_name][] = $row->sql;
        }

        return $definitions;
    }

    /**
     * @return array<string, list<string>>
     */
    private function mysqlDefinitions(): array
    {
        $definitions = [];

        foreach (DB::select('SHOW FULL TABLES WHERE Table_type = ?', ['BASE TABLE']) as $row) {
            $table = array_values((array) $row)[0];
            $create = (array) DB::selectOne('SHOW CREATE TABLE '.$this->quoteIdentifier($table));

            $definitions[$table] = [$create['Create Table']];
        }

        return $definitions;
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function tableRows(string $table): Generator
    {
        $statement = DB::connection()->getPdo()->query('SELECT * FROM '.$this->quoteIdentifier($table));

        if ($statement === false) {
            return;
        }

        $columns = [];

        for ($i = 0; $i < $statement->columnCount(); $i++) {
            $columns[] = $statement->getColumnMeta($i)['name'] ?? 'column_'.$i;
        }

        $chunk = [];

        while (($row = $statement->fetch(PDO::FETCH_NUM)) !== false) {
            $chunk[] = array_map($this->encodeValue(...), $row);

            if (count($chunk) === self::CHUNK_SIZE) {
                yield ['t' => 'rows', 'table' => $table, 'columns' => $columns, 'rows' => $chunk];
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            yield ['t' => 'rows', 'table' => $table, 'columns' => $columns, 'rows' => $chunk];
        }
    }

    /**
     * Values that are not valid UTF-8 (raw binary columns) are carried base64-encoded,
     * because JSON cannot hold them otherwise.
     */
    private function encodeValue(mixed $value): mixed
    {
        if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
            return ['__b64' => base64_encode($value)];
        }

        return $value;
    }

    private function decodeValue(mixed $value): mixed
    {
        if (is_array($value) && array_key_exists('__b64', $value)) {
            return base64_decode($value['__b64'], true);
        }

        return $value;
    }

    /**
     * @param  array{table: string, columns: list<string>, rows: list<list<mixed>>}  $record
     */
    private function insertRows(Connection $connection, array $record): void
    {
        if ($record['rows'] === []) {
            return;
        }

        $columns = implode(', ', array_map($this->quoteIdentifier(...), $record['columns']));
        $placeholders = '('.implode(', ', array_fill(0, count($record['columns']), '?')).')';
        $sql = 'INSERT INTO '.$this->quoteIdentifier($record['table'])." ({$columns}) VALUES {$placeholders}";

        $statement = $connection->getPdo()->prepare($sql);

        foreach ($record['rows'] as $row) {
            $statement->execute(array_map($this->decodeValue(...), $row));
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function assertCompatible(array $meta): void
    {
        if (($meta['format'] ?? null) !== self::FORMAT_VERSION) {
            throw new RuntimeException('This backup was written in an incompatible dump format.');
        }

        if (($meta['driver'] ?? null) !== $this->driver()) {
            throw new RuntimeException(sprintf(
                'The backup holds a %s database, this installation runs %s. Restoring across database drivers is not supported.',
                $meta['driver'] ?? 'unknown',
                $this->driver(),
            ));
        }
    }

    /**
     * Drops every table and view, one statement at a time.
     *
     * Deliberately not `Schema::dropAllTables()`: on SQLite that truncates the database
     * file outright and runs a VACUUM, which cannot happen inside a transaction. Plain
     * DROP statements work in both situations and leave the file itself alone.
     */
    private function dropEverything(): void
    {
        // Views go first, otherwise a stale one would survive the restore and shadow a
        // table the dump recreates.
        foreach ($this->existingObjects('view') as $view) {
            DB::statement('DROP VIEW IF EXISTS '.$this->quoteIdentifier($view));
        }

        foreach ($this->orderedForDrop($this->existingObjects('table')) as $table) {
            DB::statement('DROP TABLE IF EXISTS '.$this->quoteIdentifier($table));
        }
    }

    /**
     * Orders tables so that no dropped table is still referenced by one that remains.
     *
     * SQLite resolves the foreign key graph while dropping, and a table whose target is
     * already gone makes every later drop fail. Referencing tables therefore go first.
     * MySQL does not need this, its foreign key checks are genuinely switched off.
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function orderedForDrop(array $tables): array
    {
        if ($this->driver() !== 'sqlite') {
            return $tables;
        }

        $referencedBy = [];

        foreach ($tables as $table) {
            foreach (DB::select('PRAGMA foreign_key_list('.$this->quoteIdentifier($table).')') as $foreignKey) {
                $referencedBy[$foreignKey->table][$table] = true;
            }
        }

        $ordered = [];
        $remaining = $tables;

        while ($remaining !== []) {
            $free = array_values(array_filter($remaining, function (string $table) use ($referencedBy, $remaining) {
                // A table can go once nothing left references it. A self-reference does
                // not count, it disappears with the table.
                $referrers = array_intersect(array_keys($referencedBy[$table] ?? []), $remaining);

                return array_diff($referrers, [$table]) === [];
            }));

            // Circular references: drop what is left in whatever order it comes.
            if ($free === []) {
                return array_merge($ordered, $remaining);
            }

            $ordered = array_merge($ordered, $free);
            $remaining = array_values(array_diff($remaining, $free));
        }

        return $ordered;
    }

    /**
     * @return list<string>
     */
    private function existingObjects(string $type): array
    {
        if ($this->driver() === 'sqlite') {
            $rows = DB::select(
                "SELECT name FROM sqlite_master WHERE type = ? AND name NOT LIKE 'sqlite_%'",
                [$type],
            );

            return array_map(fn ($row) => $row->name, $rows);
        }

        $rows = DB::select('SHOW FULL TABLES WHERE Table_type = ?', [$type === 'view' ? 'VIEW' : 'BASE TABLE']);

        return array_map(fn ($row) => array_values((array) $row)[0], $rows);
    }

    private function withoutForeignKeyChecks(callable $callback): void
    {
        $schema = DB::connection()->getSchemaBuilder();
        $schema->disableForeignKeyConstraints();

        // `PRAGMA foreign_keys` is silently ignored while a transaction is open, which is
        // exactly the situation in the test suite. defer_foreign_keys works there and
        // postpones the check to the commit, by which time the dump is fully restored.
        if ($this->driver() === 'sqlite') {
            DB::statement('PRAGMA defer_foreign_keys = ON');
        }

        try {
            $callback();
        } finally {
            $schema->enableForeignKeyConstraints();
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        return $this->driver() === 'mysql'
            ? '`'.str_replace('`', '``', $identifier).'`'
            : '"'.str_replace('"', '""', $identifier).'"';
    }
}
