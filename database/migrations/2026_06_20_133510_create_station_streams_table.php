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
        Schema::create('station_streams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->nullable(); // Phase 4c-Produktion: → servers.id; lokal NULL
            $table->string('container_name')->unique();
            $table->string('status')->default('stopped'); // running | stopped | error
            $table->unsignedInteger('live_port')->nullable(); // input.harbor Live-Takeover-Port
            $table->text('live_password_enc')->nullable();
            $table->timestamp('last_started_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('station_streams');
    }
};
