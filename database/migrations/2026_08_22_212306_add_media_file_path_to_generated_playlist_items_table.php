<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generated_playlist_items', function (Blueprint $table) {
            // Pfad-Snapshot der Mediendatei zum Zeitpunkt der Rundown-Generierung.
            // Wird die Datei spaeter ersetzt, spielt dieser Rundown die eingefrorene
            // Version zu Ende – erst eine Neu-Generierung uebernimmt die neue Datei.
            $table->string('media_file_path')->nullable()->after('media_file_id');
        });
    }

    public function down(): void
    {
        Schema::table('generated_playlist_items', function (Blueprint $table) {
            $table->dropColumn('media_file_path');
        });
    }
};
