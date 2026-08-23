<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            // Freie Notizen zur Datei (Redaktion), z.B. „Intro 8s, nicht vor 20 Uhr".
            $table->text('notes')->nullable()->after('album');
        });
    }

    public function down(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
