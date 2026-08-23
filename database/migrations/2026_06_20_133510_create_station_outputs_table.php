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
        Schema::create('station_outputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('icecast'); // icecast | lautfm
            $table->string('host');
            $table->unsignedInteger('port')->default(8000);
            $table->string('mount');
            $table->text('password_enc')->nullable();
            $table->unsignedInteger('bitrate')->default(128);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('station_outputs');
    }
};
