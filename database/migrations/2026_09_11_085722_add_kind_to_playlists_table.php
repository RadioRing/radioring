<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Containers are playlists that are never scheduled on their own: they are
     * reusable blocks (jingle + news + ad break) embedded into real playlists.
     */
    public function up(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->enum('kind', ['playlist', 'container'])->default('playlist')->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
