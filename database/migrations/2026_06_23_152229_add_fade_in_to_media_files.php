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
            // Beim Ausspielen sanft einblenden (liq_fade_in-Annotation + fade.in im Script).
            // Nützlich z.B. für Jingles. duration kommt aus radioring.fade_in_seconds.
            $table->boolean('fade_in')->default(false)->after('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->dropColumn('fade_in');
        });
    }
};
