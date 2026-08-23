<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_file_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_file_id')->constrained()->cascadeOnDelete();
            // Ersetzte (frühere) Fassung der Datei. Sie bleibt auf der Platte liegen,
            // bis kein Rundown sie mehr referenziert (media:prune-replaced).
            $table->string('file_path');
            $table->string('original_filename')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->double('loudness_lufs')->nullable();
            $table->double('loudness_true_peak')->nullable();
            $table->foreignId('replaced_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_file_versions');
    }
};
