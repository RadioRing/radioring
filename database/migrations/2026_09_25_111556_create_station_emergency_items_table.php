<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The emergency loop a station falls back to while the programme is unavailable.
        Schema::create('station_emergency_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_file_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['station_id', 'media_file_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('station_emergency_items');
    }
};
