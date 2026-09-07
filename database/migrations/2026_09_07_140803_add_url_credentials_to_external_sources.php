<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Login for a kind=url source: FTP/FTPS credentials, or HTTP basic auth.
     *
     * Stored encrypted (see the model casts), so text columns are required: the
     * ciphertext of even a short password is a few hundred characters long.
     */
    public function up(): void
    {
        Schema::table('external_sources', function (Blueprint $table) {
            $table->text('url_username')->nullable()->after('url');
            $table->text('url_password')->nullable()->after('url_username');
        });
    }

    public function down(): void
    {
        Schema::table('external_sources', function (Blueprint $table) {
            $table->dropColumn(['url_username', 'url_password']);
        });
    }
};
