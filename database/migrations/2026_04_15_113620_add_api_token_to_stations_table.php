<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->string('api_token', 64)->unique()->nullable()->after('status');
        });

        // Bestehende Stationen bekommen einen Token
        DB::table('stations')->whereNull('api_token')->get()->each(function ($station) {
            DB::table('stations')
                ->where('id', $station->id)
                ->update(['api_token' => Str::random(64)]);
        });

        Schema::table('stations', function (Blueprint $table) {
            $table->string('api_token', 64)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->dropColumn('api_token');
        });
    }
};
