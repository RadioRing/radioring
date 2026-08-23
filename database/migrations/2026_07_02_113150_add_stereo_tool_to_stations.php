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
        Schema::table('stations', function (Blueprint $table) {
            // Nur ein Admin schaltet Stereo Tool frei (CPU-intensiv) – daher NICHT fillable.
            $table->boolean('stereo_tool_enabled')->default(false)->after('regenerate_rundowns_nightly');
            // Thimeo-Lizenzschlüssel pro Station (verschlüsselt abgelegt).
            $table->text('stereo_tool_license_key')->nullable()->after('stereo_tool_enabled');
            // Gewähltes Preset (Wert eines StereoToolPreset-Enums).
            $table->string('stereo_tool_preset')->nullable()->after('stereo_tool_license_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->dropColumn(['stereo_tool_enabled', 'stereo_tool_license_key', 'stereo_tool_preset']);
        });
    }
};
