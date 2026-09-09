<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remembers a programme underrun: /next has nothing to hand out while the station is
     * supposed to be on air. Two columns on purpose - the start is what the dashboard and
     * the protocol report, the logged marker keeps the once-per-second pull from writing a
     * protocol line on every single attempt.
     */
    public function up(): void
    {
        Schema::table('liquidsoap_states', function (Blueprint $table) {
            $table->timestamp('underrun_started_at')->nullable()->after('last_pulled_at');
            $table->timestamp('underrun_logged_at')->nullable()->after('underrun_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('liquidsoap_states', function (Blueprint $table) {
            $table->dropColumn(['underrun_started_at', 'underrun_logged_at']);
        });
    }
};
