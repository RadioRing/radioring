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
        Schema::create('external_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Art der Quelle: frei konfigurierbare URL oder laut.fm-Nachrichten/Wetter
            // (die ihre URL zur Laufzeit aus den Ausgangs-Credentials bauen).
            $table->enum('kind', ['url', 'news', 'weather', 'news_weather']);
            $table->string('url', 2048)->nullable();
            $table->unsignedInteger('expected_duration_seconds')->nullable();
            // Vorlauf, mit dem der Inhalt vor Ausspielung geholt/analysiert wird.
            $table->unsignedInteger('prefetch_lead_seconds')->default(180);
            // Wie lange eine bereits geholte Kopie wiederverwendet werden darf
            // (0 = immer frisch holen; News kurz, Syndication evtl. länger).
            $table->unsignedInteger('freshness_seconds')->default(0);
            // Lautheit auf die vorbereitete Kopie anwenden (nur bei rechtzeitig fertiger Messung).
            $table->boolean('normalize')->default(true);
            // Betriebs-Status des letzten Abrufs (für die Bibliothek/Debugging).
            $table->timestamp('last_fetched_at')->nullable();
            $table->double('last_loudness_lufs')->nullable();
            $table->double('last_true_peak')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('external_sources');
    }
};
