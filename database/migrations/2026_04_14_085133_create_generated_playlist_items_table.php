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
        Schema::create('generated_playlist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('generated_playlist_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_file_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('position');
            $table->string('title');
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->dateTime('absolute_broadcast_at')->nullable();
            $table->string('source_type')->default('template_item'); // template_item|resolved_fill|manual
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generated_playlist_items');
    }
};
