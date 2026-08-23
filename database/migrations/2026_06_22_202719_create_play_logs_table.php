<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sendeprotokoll: Was de facto rausging (was man hört). Wird vom
     * Now-Playing-Callback gefüllt – sowohl reguläre Rundown-Tracks (source=playlist)
     * als auch Live-Übernahmen (source=live). Denormalisierter Snapshot, damit das
     * Protokoll auch nach Löschen des Items/der Mediendatei lesbar bleibt.
     */
    public function up(): void
    {
        Schema::create('play_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->constrained()->cascadeOnDelete();
            $table->string('source'); // 'playlist' | 'live'
            $table->foreignId('media_file_id')->nullable()->nullOnDelete();
            $table->foreignId('generated_playlist_item_id')->nullable()->nullOnDelete();
            $table->string('title')->nullable();
            $table->string('artist')->nullable();
            $table->string('source_type')->nullable(); // music/news/weather/... bei Playlist
            $table->timestamp('played_at');
            $table->timestamps();

            $table->index(['station_id', 'played_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('play_logs');
    }
};
