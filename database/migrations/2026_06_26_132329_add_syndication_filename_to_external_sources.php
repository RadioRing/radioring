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
        Schema::table('external_sources', function (Blueprint $table) {
            // Eine Syndication-Quelle ist an genau eine Datei der Sendung gepinnt
            // (Sendungen mit mehreren Dateien werden zu mehreren Quellen). resolveUrl
            // sucht beim Abruf die signierte URL dieser Datei anhand des Dateinamens.
            $table->string('syndication_filename')->nullable()->after('syndication_variant');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('external_sources', function (Blueprint $table) {
            $table->dropColumn('syndication_filename');
        });
    }
};
