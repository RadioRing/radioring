<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('station_users', function (Blueprint $table) {
            $table->enum('role', ['owner', 'editor', 'presenter'])->default('editor')->change();
        });
    }

    public function down(): void
    {
        DB::table('station_users')->where('role', 'presenter')->update(['role' => 'editor']);

        Schema::table('station_users', function (Blueprint $table) {
            $table->enum('role', ['owner', 'editor'])->default('editor')->change();
        });
    }
};
