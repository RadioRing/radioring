<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->boolean('alert_emails_enabled')->default(true);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('receives_alert_emails')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->dropColumn('alert_emails_enabled');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('receives_alert_emails');
        });
    }
};
