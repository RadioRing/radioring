<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stereo_tool_presets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('path');
            $table->unsignedInteger('size');
            $table->timestamps();

            $table->index('station_id');
        });

        // Values of the removed StereoToolPreset enum, whose .sts files were never
        // shipped. Cleared rather than left dangling; stations keep running without them.
        DB::table('stations')
            ->whereIn('stereo_tool_preset', ['neutral', 'pop', 'talk', 'loud'])
            ->update(['stereo_tool_preset' => null]);
    }

    public function down(): void
    {
        Schema::dropIfExists('stereo_tool_presets');
    }
};
