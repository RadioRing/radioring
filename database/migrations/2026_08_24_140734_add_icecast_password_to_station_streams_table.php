<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Source password of the station's own Icecast sidecar.
     *
     * It appears in the generated .liq script and in the sidecar's env, so it has to stay
     * stable across restarts. The admin password used for the status query comes from the
     * config instead and is shared by all sidecars.
     */
    public function up(): void
    {
        Schema::table('station_streams', function (Blueprint $table) {
            $table->text('icecast_password_enc')->nullable()->after('live_password_enc');
        });
    }

    public function down(): void
    {
        Schema::table('station_streams', function (Blueprint $table) {
            $table->dropColumn('icecast_password_enc');
        });
    }
};
