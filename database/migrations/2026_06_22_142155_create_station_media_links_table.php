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
        Schema::create('station_media_links', function (Blueprint $table) {
            $table->id();
            // Konsumierende Station (verlinkt eine fremde Datei in ihren Pool).
            $table->foreignId('station_id')->constrained()->cascadeOnDelete();
            // Quell-Mediendatei (gehört einer anderen Station desselben Besitzers).
            $table->foreignId('media_file_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['station_id', 'media_file_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('station_media_links');
    }
};
