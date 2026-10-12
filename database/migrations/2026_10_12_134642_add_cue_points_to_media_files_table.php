<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            // Cue and fade points set in the waveform editor, in seconds of the file.
            // Null means "not set": cue in at the start, cue out at the end, no fade.
            // Liquidsoap applies them per element (liq_cue_in/liq_cue_out on request
            // resolution, liq_fade_in/liq_fade_out in the programme branch).
            $table->double('cue_in_seconds')->nullable()->after('fade_in');
            $table->double('cue_out_seconds')->nullable()->after('cue_in_seconds');
            $table->double('fade_in_seconds')->nullable()->after('cue_out_seconds');
            $table->double('fade_out_seconds')->nullable()->after('fade_in_seconds');
        });

        // The old checkbox faded in for the configured duration; keep that per file.
        DB::table('media_files')
            ->where('fade_in', true)
            ->update(['fade_in_seconds' => (float) config('radioring.fade_in_seconds', 2.0)]);

        Schema::table('media_files', function (Blueprint $table) {
            $table->dropColumn('fade_in');
        });

        // Cue points belong to one version of the file: restoring a version brings its
        // cue points back.
        Schema::table('media_file_versions', function (Blueprint $table) {
            $table->double('cue_in_seconds')->nullable()->after('loudness_true_peak');
            $table->double('cue_out_seconds')->nullable()->after('cue_in_seconds');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('media_file_versions', function (Blueprint $table) {
            $table->dropColumn(['cue_in_seconds', 'cue_out_seconds']);
        });

        Schema::table('media_files', function (Blueprint $table) {
            $table->boolean('fade_in')->default(false)->after('type');
        });

        DB::table('media_files')
            ->whereNotNull('fade_in_seconds')
            ->where('fade_in_seconds', '>', 0)
            ->update(['fade_in' => true]);

        Schema::table('media_files', function (Blueprint $table) {
            $table->dropColumn(['cue_in_seconds', 'cue_out_seconds', 'fade_in_seconds', 'fade_out_seconds']);
        });
    }
};
