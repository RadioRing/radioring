<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generated_playlist_items', function (Blueprint $table) {
            // Nominal fixed time from the marker in front of the item.
            $table->dateTime('fixed_at')->nullable()->after('absolute_broadcast_at');
            // soft or hard.
            $table->string('fixed_mode', 8)->nullable()->after('fixed_at');
            // Fill track dropped by the playout for a fixed time.
            $table->timestamp('skipped_at')->nullable()->after('fixed_mode');
        });
    }

    public function down(): void
    {
        Schema::table('generated_playlist_items', function (Blueprint $table) {
            $table->dropColumn(['fixed_at', 'fixed_mode', 'skipped_at']);
        });
    }
};
