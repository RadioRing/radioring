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
            $table->unsignedInteger('relative_offset_seconds')->nullable()->after('duration_seconds');
            $table->json('fill_tags')->nullable()->after('relative_offset_seconds');
            $table->unsignedInteger('fill_max_duration_seconds')->nullable()->after('fill_tags');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('playlist_items', function (Blueprint $table) {
            $table->dropColumn(['relative_offset_seconds', 'fill_tags', 'fill_max_duration_seconds']);
        });
    }
};
