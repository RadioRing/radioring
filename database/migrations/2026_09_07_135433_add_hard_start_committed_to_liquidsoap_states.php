<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Field to remember if hard start was commited
     */
    public function up(): void
    {
        Schema::table('liquidsoap_states', function (Blueprint $table) {
            $table->foreignId('hard_start_committed_rundown_id')
                ->nullable()
                ->after('current_item_position')
                ->constrained('generated_playlists')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('liquidsoap_states', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hard_start_committed_rundown_id');
        });
    }
};
