<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('station_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            // First seen; the mail waits for the configured delay.
            $table->timestamp('started_at');
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('resolved_notified_at')->nullable();
            $table->timestamps();

            $table->index(['station_id', 'type', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('station_alerts');
    }
};
