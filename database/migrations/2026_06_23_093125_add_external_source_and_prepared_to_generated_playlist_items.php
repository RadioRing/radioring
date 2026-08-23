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
        Schema::table('generated_playlist_items', function (Blueprint $table) {
            $table->foreignId('external_source_id')->nullable()->after('media_file_id')
                ->constrained()->nullOnDelete();
            // Lokal vorbereitete (heruntergeladene) Kopie des dynamischen Inhalts kurz vor
            // Ausspielung – plus deren Lautheitsmessung (pro Ausspielung, nicht pro Quelle).
            $table->string('prepared_path')->nullable()->after('external_source_id');
            $table->timestamp('prepared_at')->nullable()->after('prepared_path');
            $table->double('loudness_lufs')->nullable()->after('prepared_at');
            $table->double('loudness_true_peak')->nullable()->after('loudness_lufs');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('generated_playlist_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('external_source_id');
            $table->dropColumn(['prepared_path', 'prepared_at', 'loudness_lufs', 'loudness_true_peak']);
        });
    }
};
