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
        Schema::table('playlist_items', function (Blueprint $table) {
            $table->enum('type', ['music', 'jingle', 'url', 'fill', 'adbreak', 'news', 'weather', 'news_weather', 'external', 'random'])->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('playlist_items', function (Blueprint $table) {
            $table->enum('type', ['music', 'jingle', 'url', 'fill', 'adbreak', 'news', 'weather', 'news_weather', 'external'])->change();
        });
    }
};
