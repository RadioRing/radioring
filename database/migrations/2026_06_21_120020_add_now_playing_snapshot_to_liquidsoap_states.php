<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Denormalisierter Snapshot des laufenden Tracks. Hält die Anzeige am Leben,
     * wenn das zugehörige GeneratedPlaylistItem verschwindet (z. B. weil der Rundown
     * während des Sendens neu generiert wird und die alten Items gelöscht werden).
     */
    public function up(): void
    {
        Schema::table('liquidsoap_states', function (Blueprint $table) {
            $table->string('now_playing_title')->nullable()->after('now_playing_item_id');
            $table->string('now_playing_source_type')->nullable()->after('now_playing_title');
            $table->unsignedInteger('now_playing_duration_seconds')->nullable()->after('now_playing_source_type');
        });
    }

    public function down(): void
    {
        Schema::table('liquidsoap_states', function (Blueprint $table) {
            $table->dropColumn(['now_playing_title', 'now_playing_source_type', 'now_playing_duration_seconds']);
        });
    }
};
