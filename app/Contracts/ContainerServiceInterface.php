<?php

namespace App\Contracts;

use App\Models\Station;

interface ContainerServiceInterface
{
    /**
     * Erstellt (falls nötig) und startet den Liquidsoap-Container der Station.
     */
    public function startStationContainer(Station $station): bool;

    /**
     * Stoppt und entfernt den Container der Station.
     */
    public function stopStationContainer(Station $station): bool;

    /**
     * Startet den bestehenden Container neu (zieht das .liq-Script frisch).
     */
    public function restartStationContainer(Station $station): bool;

    /**
     * Liefert den Docker-Status des Containers (running|exited|not_found|null).
     */
    public function containerState(Station $station): ?string;

    /**
     * Ist der Treiber ausreichend konfiguriert, um genutzt zu werden?
     *
     * Darf keine Netzwerkzugriffe machen: die Methode laeuft im Request-Pfad, damit
     * die Oberflaeche entscheiden kann, ob sie die Steuer-Buttons anbietet.
     */
    public function isConfigured(): bool;
}
