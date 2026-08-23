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
        Schema::create('generated_playlists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hour_grid_slot_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('playlist_id')->nullable()->constrained()->nullOnDelete();
            $table->date('broadcast_date');
            $table->tinyInteger('broadcast_hour');  // 0–23
            $table->string('status')->default('draft');  // draft|ready|played
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique(['station_id', 'broadcast_date', 'broadcast_hour'], 'gen_playlists_station_date_hour_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generated_playlists');
    }
};
