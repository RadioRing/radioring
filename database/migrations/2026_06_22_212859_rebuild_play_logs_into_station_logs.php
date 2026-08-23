<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Baut das reine Sendeprotokoll (play_logs) zu einem allgemeinen Stations-Protokoll
     * (station_logs) um: zusätzlich zu gespielten Tracks werden nun auch Ereignisse wie
     * Rundown-Generierung und Live-/Playlist-Wechsel festgehalten. Bestehende Zeilen
     * sind Track-Einträge und bekommen event='track'.
     */
    public function up(): void
    {
        Schema::rename('play_logs', 'station_logs');

        Schema::table('station_logs', function (Blueprint $table) {
            $table->string('event')->default('track')->after('station_id');
            $table->foreignId('generated_playlist_id')->nullable()->after('generated_playlist_item_id')->constrained()->nullOnDelete();
            $table->string('message')->nullable()->after('source_type');
            // source gilt nur für Track-Events – für andere Ereignisse (Live-Wechsel,
            // Rundown-Generierung) bleibt sie leer.
            $table->string('source')->nullable()->change();
        });

        Schema::table('station_logs', function (Blueprint $table) {
            $table->renameColumn('played_at', 'occurred_at');
        });
    }

    public function down(): void
    {
        Schema::table('station_logs', function (Blueprint $table) {
            $table->renameColumn('occurred_at', 'played_at');
        });

        Schema::table('station_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('generated_playlist_id');
            $table->dropColumn(['event', 'message']);
        });

        Schema::rename('station_logs', 'play_logs');
    }
};
