<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the voice track as a third media type next to music and jingle.
 *
 * A media element in a playlist carries the type of its file, so both enums grow.
 */
return new class extends Migration
{
    /** @var list<string> element types before voice tracks */
    private const ITEM_TYPES = ['music', 'jingle', 'url', 'fill', 'adbreak', 'news', 'weather', 'news_weather', 'external', 'random', 'container', 'marker'];

    public function up(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->enum('type', ['music', 'jingle', 'voicetrack'])->change();
        });

        Schema::table('playlist_items', function (Blueprint $table) {
            $table->enum('type', [...self::ITEM_TYPES, 'voicetrack'])->change();
        });
    }

    public function down(): void
    {
        DB::table('playlist_items')->where('type', 'voicetrack')->update(['type' => 'jingle']);
        DB::table('media_files')->where('type', 'voicetrack')->update(['type' => 'jingle']);

        Schema::table('playlist_items', function (Blueprint $table) {
            $table->enum('type', self::ITEM_TYPES)->change();
        });

        Schema::table('media_files', function (Blueprint $table) {
            $table->enum('type', ['music', 'jingle'])->change();
        });
    }
};
