<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Splits the two roles the name used to play at once.
     *
     * The name is the operator's label: it identifies the source in the library, the
     * playlist editor and the log, which is why parts of one show tend to be numbered.
     * That numbering has no business going on air. broadcast_title is what listeners
     * see; left empty, the name is annotated exactly as before.
     */
    public function up(): void
    {
        Schema::table('external_sources', function (Blueprint $table) {
            $table->string('broadcast_title')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('external_sources', function (Blueprint $table) {
            $table->dropColumn('broadcast_title');
        });
    }
};
