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
            // Offline gemessene EBU-R128-Lautheit (per ffmpeg beim Upload). Ersetzt die
            // crashende Live-Autocue-Messung im Streamer: beim Ausspielen wird daraus nur
            // noch der liq_amplify-Gain berechnet und annotiert.
            $table->double('loudness_lufs')->nullable()->after('duration_seconds');
            $table->double('loudness_true_peak')->nullable()->after('loudness_lufs');
            $table->timestamp('loudness_measured_at')->nullable()->after('loudness_true_peak');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->dropColumn(['loudness_lufs', 'loudness_true_peak', 'loudness_measured_at']);
        });
    }
};
