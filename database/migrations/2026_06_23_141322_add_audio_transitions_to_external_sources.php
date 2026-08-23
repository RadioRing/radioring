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
            // Führende Stille beim Vorbereiten offline (ffmpeg) wegschneiden – behebt
            // das hörbare Loch z.B. vor dem laut.fm-Nachrichten-Intro.
            $table->boolean('trim_leading_silence')->default(false)->after('normalize');
            // Beim Ausspielen sanft einblenden (liq_fade_in-Annotation + fade.in im Script).
            $table->boolean('fade_in')->default(false)->after('trim_leading_silence');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('external_sources', function (Blueprint $table) {
            $table->dropColumn(['trim_leading_silence', 'fade_in']);
        });
    }
};
