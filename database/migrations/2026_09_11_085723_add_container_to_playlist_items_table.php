<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('playlist_items', function (Blueprint $table) {
            $table->enum('type', ['music', 'jingle', 'url', 'fill', 'adbreak', 'news', 'weather', 'news_weather', 'external', 'random', 'container'])->change();
        });

        Schema::table('playlist_items', function (Blueprint $table) {
            $table->foreignId('container_playlist_id')->nullable()->after('external_source_id')
                ->constrained('playlists')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('playlist_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('container_playlist_id');
        });

        Schema::table('playlist_items', function (Blueprint $table) {
            $table->enum('type', ['music', 'jingle', 'url', 'fill', 'adbreak', 'news', 'weather', 'news_weather', 'external', 'random'])->change();
        });
    }
};
