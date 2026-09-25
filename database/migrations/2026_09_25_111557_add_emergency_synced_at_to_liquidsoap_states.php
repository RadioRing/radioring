<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('liquidsoap_states', function (Blueprint $table) {
            // Last time the container fetched the emergency manifest.
            $table->timestamp('emergency_synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('liquidsoap_states', function (Blueprint $table) {
            $table->dropColumn('emergency_synced_at');
        });
    }
};
