<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per backup run, successful or not.
     *
     * The row is the only index of what lies in storage/app/private/backups: retention,
     * the download link and the failure message all hang off it. A row without a file is
     * a failed run and is kept on purpose, otherwise a nightly backup could fail for
     * weeks without anybody noticing.
     */
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->string('filename')->nullable();
            $table->string('kind')->default('config');
            $table->string('status')->default('pending');
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->boolean('encrypted')->default(false);
            $table->boolean('automatic')->default(false);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
