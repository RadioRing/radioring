<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Failure bookkeeping for the preparation of external items. Without it a source that
     * cannot be downloaded is retried on every scheduler tick for the whole prefetch lead.
     */
    public function up(): void
    {
        Schema::table('generated_playlist_items', function (Blueprint $table) {
            $table->unsignedSmallInteger('prepare_attempts')->default(0)->after('prepared_at');
            $table->timestamp('prepare_failed_at')->nullable()->after('prepare_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('generated_playlist_items', function (Blueprint $table) {
            $table->dropColumn(['prepare_attempts', 'prepare_failed_at']);
        });
    }
};
