<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            // soft = Überhang erlaubt, startet am nächsten Track-Übergang
            // hard = startet zur vollen Stunde (schneidet Überhang am Track-Ende ab)
            $table->string('start_mode')->default('soft')->after('playback_mode');
        });

        // Bestehende Hard-Slots auf ihre Playlist übertragen, damit nichts verloren geht.
        DB::table('playlists')
            ->whereIn('id', DB::table('hour_grid_slots')->where('start_mode', 'hard')->pluck('playlist_id'))
            ->update(['start_mode' => 'hard']);

        Schema::table('hour_grid_slots', function (Blueprint $table) {
            $table->dropColumn('start_mode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hour_grid_slots', function (Blueprint $table) {
            $table->string('start_mode')->default('soft')->after('playlist_id');
        });

        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn('start_mode');
        });
    }
};
