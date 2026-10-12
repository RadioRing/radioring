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
        Schema::table('media_files', function (Blueprint $table) {
            // Precomputed waveform peaks (JSON on the local disk), generated offline by
            // ffmpeg. Null until the job has run, and again after the file was replaced.
            $table->string('waveform_path')->nullable()->after('loudness_measured_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->dropColumn('waveform_path');
        });
    }
};
