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
        Schema::table('liquidsoap_states', function (Blueprint $table) {
            $table->string('now_playing_artist')->nullable()->after('now_playing_title');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('liquidsoap_states', function (Blueprint $table) {
            $table->dropColumn('now_playing_artist');
        });
    }
};
