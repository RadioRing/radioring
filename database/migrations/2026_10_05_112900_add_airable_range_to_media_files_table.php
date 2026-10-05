<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An optional run time on top of the airtime windows: a Christmas jingle only in
     * December, a trailer that expires tonight. Either end may stay open.
     */
    public function up(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->dateTime('airable_from')->nullable()->after('airtime_windows');
            $table->dateTime('airable_until')->nullable()->after('airable_from');
        });
    }

    public function down(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->dropColumn(['airable_from', 'airable_until']);
        });
    }
};
