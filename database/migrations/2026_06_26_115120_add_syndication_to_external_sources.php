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
            // Neue Art: aus Syndications4Radio importierte Sendung. Die URL wird zur
            // Laufzeit über die Partner-API als signierte Download-URL aufgelöst.
            $table->enum('kind', ['url', 'news', 'weather', 'news_weather', 'syndication'])->change();

            // Referenz auf die S4R-Sendung und die gewählte Datei-Variante (lfm|normal).
            $table->unsignedBigInteger('syndication_sendung_id')->nullable()->after('url');
            $table->string('syndication_variant')->nullable()->after('syndication_sendung_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('external_sources', function (Blueprint $table) {
            $table->dropColumn(['syndication_sendung_id', 'syndication_variant']);
            $table->enum('kind', ['url', 'news', 'weather', 'news_weather'])->change();
        });
    }
};
