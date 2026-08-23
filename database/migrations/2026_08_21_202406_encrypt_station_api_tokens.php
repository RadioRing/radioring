<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Verschluesselt stations.api_token im Bestand.
     *
     * Der Token ist ein geteiltes Geheimnis mit dem Station-Container und muss daher im
     * Klartext lesbar bleiben (der Container bekommt ihn als Env-Variable). Hashen wie
     * bei einem Passwort scheidet damit aus, Verschluesselung ist das Maximum.
     *
     * Zwei Anpassungen am Schema sind dafuer noetig:
     *   - string(64) reicht nicht, ein verschluesselter Wert ist ein Vielfaches laenger.
     *   - Der unique()-Index muss weg: Laravels Verschluesselung ist nicht
     *     deterministisch, zwei gleiche Klartexte ergeben verschiedene Chiffrate. Der
     *     Index koennte also nichts mehr garantieren, und MySQL erlaubt auf TEXT ohnehin
     *     keinen Index ohne Praefixlaenge.
     *
     * Eindeutigkeit stellt weiterhin Str::random(64) sicher (rund 380 Bit Entropie).
     */
    public function up(): void
    {
        $this->dropUniqueIndex();

        Schema::table('stations', function (Blueprint $table) {
            $table->text('api_token')->nullable()->change();
        });

        $this->encryptExistingTokens();
    }

    /**
     * Verschluesselt jeden Klartext-Token. Idempotent: bereits verschluesselte Werte
     * werden erkannt und uebersprungen, damit ein Wiederholungslauf nach einem
     * Teilabbruch nichts doppelt verschluesselt.
     */
    private function encryptExistingTokens(): void
    {
        DB::table('stations')->orderBy('id')->chunkById(200, function ($stations) {
            foreach ($stations as $station) {
                if ($station->api_token === null || $station->api_token === '') {
                    continue;
                }

                if ($this->isEncrypted($station->api_token)) {
                    continue;
                }

                DB::table('stations')
                    ->where('id', $station->id)
                    ->update(['api_token' => Crypt::encryptString($station->api_token)]);
            }
        });
    }

    private function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Loest den unique()-Index, sofern vorhanden. Der Name kann von der Laravel-Vorgabe
     * abweichen, deshalb wird er aus dem laufenden Schema gelesen statt geraten.
     */
    private function dropUniqueIndex(): void
    {
        foreach (Schema::getIndexes('stations') as $index) {
            if ($index['columns'] === ['api_token'] && ! $index['primary']) {
                Schema::table('stations', function (Blueprint $table) use ($index) {
                    $table->dropIndex($index['name']);
                });
            }
        }
    }

    public function down(): void
    {
        DB::table('stations')->orderBy('id')->chunkById(200, function ($stations) {
            foreach ($stations as $station) {
                if ($station->api_token === null || ! $this->isEncrypted($station->api_token)) {
                    continue;
                }

                DB::table('stations')
                    ->where('id', $station->id)
                    ->update(['api_token' => Crypt::decryptString($station->api_token)]);
            }
        });

        Schema::table('stations', function (Blueprint $table) {
            $table->string('api_token', 64)->nullable(false)->change();
            $table->unique('api_token');
        });
    }
};
