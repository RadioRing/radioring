<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('liquidsoap_states', function (Blueprint $table) {
            $table->boolean('live_active')->default(false)->after('now_playing_started_at');
            $table->string('live_title')->nullable()->after('live_active');
            $table->string('live_artist')->nullable()->after('live_title');
            $table->timestamp('live_started_at')->nullable()->after('live_artist');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('liquidsoap_states', function (Blueprint $table) {
            $table->dropColumn(['live_active', 'live_title', 'live_artist', 'live_started_at']);
        });
    }
};
