<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Airtime windows gate a file for the automatic pickers (fill and random elements):
     * null means "may air at any time", which keeps every existing file unchanged.
     */
    public function up(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->json('airtime_windows')->nullable()->after('fade_in');
        });
    }

    public function down(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->dropColumn('airtime_windows');
        });
    }
};
