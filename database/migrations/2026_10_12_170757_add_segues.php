<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Segues ("start next"): the next element starts at the segue point while the current
     * one fades out underneath. Opt-in per station, off by default; the segue point belongs
     * to the file version like its cue points.
     */
    public function up(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->boolean('segues_enabled')->default(false)->after('artist_separation_minutes');
        });

        Schema::table('media_files', function (Blueprint $table) {
            $table->double('segue_seconds')->nullable()->after('cue_out_seconds');
        });

        Schema::table('media_file_versions', function (Blueprint $table) {
            $table->double('segue_seconds')->nullable()->after('cue_out_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('media_file_versions', function (Blueprint $table) {
            $table->dropColumn('segue_seconds');
        });

        Schema::table('media_files', function (Blueprint $table) {
            $table->dropColumn('segue_seconds');
        });

        Schema::table('stations', function (Blueprint $table) {
            $table->dropColumn('segues_enabled');
        });
    }
};
