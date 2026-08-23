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
        Schema::create('liquidsoap_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('current_rundown_id')->nullable()->constrained('generated_playlists')->nullOnDelete();
            $table->unsignedInteger('current_item_position')->default(0);
            $table->foreignId('now_playing_item_id')->nullable()->constrained('generated_playlist_items')->nullOnDelete();
            $table->timestamp('now_playing_started_at')->nullable();
            $table->timestamp('last_pulled_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('liquidsoap_states');
    }
};
