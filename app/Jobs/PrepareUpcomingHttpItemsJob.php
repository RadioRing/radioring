<?php

namespace App\Jobs;

use App\Models\ExternalSource;
use App\Models\GeneratedPlaylistItem;
use App\Models\Station;
use App\Services\ExternalItemPreparer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/**
 * Holt dynamische externe Inhalte (HTTP/HTTPS/FTP/FTPS) kurz vor ihrer Ausspielung herunter, prüft/misst
 * sie und legt sie als lokale Kopie ab. Der /next-Endpunkt liefert dann die vorbereitete
 * Kopie (inkl. liq_amplify) statt der externen URL – mit Direkt-Passthrough als Fallback.
 *
 * Läuft minütlich (wie EnforceHardStarts). Vorlauf & Frische steuert jede ExternalSource.
 */
class PrepareUpcomingHttpItemsJob implements ShouldQueue
{
    use Queueable;

    /** Kulanz nach der geschätzten Sendezeit, bis ein Item nicht mehr vorbereitet wird. */
    private const PAST_GRACE_SECONDS = 60;

    public function handle(ExternalItemPreparer $preparer): void
    {
        $this->cleanupStalePreparedFiles();

        // Nur Stationen mit laufendem Container betrachten.
        $stations = Station::whereHas('stream', fn ($q) => $q->where('status', 'running'))->get();

        foreach ($stations as $station) {
            $items = GeneratedPlaylistItem::query()
                ->where('source_type', 'external')
                ->whereNotNull('external_source_id')
                ->whereNotNull('absolute_broadcast_at')
                ->where('absolute_broadcast_at', '>=', now()->subSeconds(self::PAST_GRACE_SECONDS))
                ->whereHas('generatedPlaylist', fn ($q) => $q->where('station_id', $station->id)->where('status', 'ready'))
                ->with('externalSource')
                ->get();

            foreach ($items as $item) {
                $source = $item->externalSource;

                if (! $source || ! $this->isDue($item, $source) || ! $this->needsPreparing($item, $source)) {
                    continue;
                }

                $preparer->prepare($item, $source, $station);
            }
        }
    }

    /** Liegt die geschätzte Sendezeit innerhalb des Quellen-Vorlaufs? */
    private function isDue(GeneratedPlaylistItem $item, ExternalSource $source): bool
    {
        return $item->absolute_broadcast_at->lte(now()->addSeconds($source->prefetch_lead_seconds));
    }

    private function needsPreparing(GeneratedPlaylistItem $item, ExternalSource $source): bool
    {
        // Noch nie vorbereitet oder Datei verschwunden → vorbereiten.
        if (! $item->prepared_path || ! Storage::disk('local')->exists($item->prepared_path)) {
            return true;
        }

        // Frische-Fenster gesetzt und überschritten → neu holen.
        return $source->freshness_seconds > 0
            && $item->prepared_at !== null
            && $item->prepared_at->lt(now()->subSeconds($source->freshness_seconds));
    }

    /**
     * Entfernt vorbereitete Kopien längst gesendeter Items, damit der Cache nicht wächst.
     */
    private function cleanupStalePreparedFiles(): void
    {
        GeneratedPlaylistItem::query()
            ->whereNotNull('prepared_path')
            ->where('absolute_broadcast_at', '<', now()->subHour())
            ->get()
            ->each(function (GeneratedPlaylistItem $item) {
                Storage::disk('local')->delete($item->prepared_path);
                $item->update(['prepared_path' => null]);
            });
    }
}
