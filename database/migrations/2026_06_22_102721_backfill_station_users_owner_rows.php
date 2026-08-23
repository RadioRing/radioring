<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill: jeder bestehende Stationsbesitzer bekommt eine owner-Zeile im Pivot,
     * damit der Zugriff einheitlich über station_users läuft.
     */
    public function up(): void
    {
        $now = now();

        DB::table('stations')->orderBy('id')->chunkById(200, function ($stations) use ($now) {
            foreach ($stations as $station) {
                DB::table('station_users')->updateOrInsert(
                    ['station_id' => $station->id, 'user_id' => $station->user_id],
                    ['role' => 'owner', 'created_at' => $now, 'updated_at' => $now],
                );
            }
        });
    }

    public function down(): void
    {
        DB::table('station_users')->where('role', 'owner')->delete();
    }
};
