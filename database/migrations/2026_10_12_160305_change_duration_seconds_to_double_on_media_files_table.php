<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The file length becomes exact: the waveform job decodes the whole file and stores the
     * real length, which a segue needs as cue out. Whole seconds from getID3 stay valid
     * until then.
     */
    public function up(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->double('duration_seconds')->nullable()->change();
        });

        Schema::table('media_file_versions', function (Blueprint $table) {
            $table->double('duration_seconds')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->unsignedInteger('duration_seconds')->nullable()->change();
        });

        Schema::table('media_file_versions', function (Blueprint $table) {
            $table->integer('duration_seconds')->nullable()->change();
        });
    }
};
