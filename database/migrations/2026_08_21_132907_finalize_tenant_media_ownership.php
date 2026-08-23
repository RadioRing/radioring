<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Locks in the tenant ownership: tenant_id becomes required, the old per-station
     * ownership of media files and tags is dropped, and station_media_links disappears:
     * every station of a tenant now sees the whole library, so linking is obsolete.
     *
     * users.station_quota moves to tenants.station_quota (already backfilled).
     *
     * Written defensively on purpose: the schema of long-lived installations does not
     * always match what the original migrations would produce (a foreign key may be
     * missing or carry a non-default name), and this migration must not fail halfway
     * through and leave the database in a mixed state. Every step therefore inspects
     * the live schema instead of assuming a name.
     */
    public function up(): void
    {
        Schema::dropIfExists('station_media_links');

        $this->dropColumnWithConstraints('media_files', 'station_id');
        $this->dropColumnWithConstraints('tags', 'station_id');

        if (Schema::hasColumn('users', 'station_quota')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('station_quota');
            });
        }

        // Tighten tenant_id to NOT NULL *in place*. Dropping and re-adding the column
        // would discard the values the backfill migration just wrote.
        $this->makeTenantIdRequired('media_files');
        $this->makeTenantIdRequired('tags');
        $this->makeTenantIdRequired('stations');

        if (! $this->hasIndexOn('tags', ['tenant_id', 'name'])) {
            Schema::table('tags', function (Blueprint $table) {
                $table->unique(['tenant_id', 'name']);
            });
        }
    }

    /**
     * Drops a column together with whatever foreign keys and indexes reference it,
     * resolving their real names from the live schema. Does nothing if the column is
     * already gone, so the migration can be retried after a partial failure.
     */
    private function dropColumnWithConstraints(string $table, string $column): void
    {
        if (! Schema::hasColumn($table, $column)) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            // SQLite rejects dropping a foreign key by name but resolves the columns form
            // while rebuilding the table, so both steps have to happen in one blueprint.
            // Indexes over the column must go first, otherwise SQLite refuses the rebuild.
            foreach ($this->indexesOn($table, $column) as $indexName) {
                Schema::table($table, function (Blueprint $blueprint) use ($indexName) {
                    $blueprint->dropIndex($indexName);
                });
            }

            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->dropForeign([$column]);
                $blueprint->dropColumn($column);
            });

            return;
        }

        // Other drivers need the real constraint name, which may differ from the Laravel
        // default or be missing entirely on databases that grew over time.
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if (in_array($column, $foreignKey['columns'], true)) {
                Schema::table($table, function (Blueprint $blueprint) use ($foreignKey) {
                    $blueprint->dropForeign($foreignKey['name']);
                });
            }
        }

        foreach ($this->indexesOn($table, $column) as $indexName) {
            Schema::table($table, function (Blueprint $blueprint) use ($indexName) {
                $blueprint->dropIndex($indexName);
            });
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column) {
            $blueprint->dropColumn($column);
        });
    }

    /**
     * Names of the non-primary indexes that cover the given column.
     *
     * @return list<string>
     */
    private function indexesOn(string $table, string $column): array
    {
        $names = [];

        foreach (Schema::getIndexes($table) as $index) {
            if (in_array($column, $index['columns'], true) && ! $index['primary']) {
                $names[] = $index['name'];
            }
        }

        return $names;
    }

    /**
     * Makes tenant_id NOT NULL without touching the stored values.
     */
    private function makeTenantIdRequired(string $table): void
    {
        if (! Schema::hasColumn($table, 'tenant_id')) {
            return;
        }

        // A row without a tenant would fail the NOT NULL switch. This should not happen
        // after the backfill, but fail loudly rather than silently dropping data.
        $orphans = DB::table($table)->whereNull('tenant_id')->count();

        if ($orphans > 0) {
            throw new RuntimeException(
                "Cannot finalize tenant ownership: {$orphans} row(s) in `{$table}` have no tenant_id. ".
                'Check the backfill migration before retrying.'
            );
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->unsignedBigInteger('tenant_id')->nullable(false)->change();
        });
    }

    /**
     * @param  list<string>  $columns
     */
    private function hasIndexOn(string $table, array $columns): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['columns'] === $columns) {
                return true;
            }
        }

        return false;
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedTinyInteger('station_quota')->default(3);
        });

        Schema::table('media_files', function (Blueprint $table) {
            $table->foreignId('station_id')->nullable()->constrained()->cascadeOnDelete();
        });

        Schema::table('tags', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'name']);
            $table->foreignId('station_id')->nullable()->constrained()->cascadeOnDelete();
        });

        Schema::create('station_media_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_file_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['station_id', 'media_file_id']);
        });
    }
};
