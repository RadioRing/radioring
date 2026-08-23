<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the nullable tenant_id columns. The backfill runs in the next migration,
     * the columns become non-nullable in the one after that.
     *
     * On users, tenant_id is the *home* tenant only: where new stations and uploads
     * of that user go by default. It must never be used to derive access; access is
     * always derived via station_users → stations → tenants.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        Schema::table('stations', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
        });

        Schema::table('media_files', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        Schema::table('tags', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['users', 'stations', 'media_files', 'tags'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropConstrainedForeignId('tenant_id');
            });
        }
    }
};
