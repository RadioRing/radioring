<?php

namespace App\Jobs;

use App\Models\ExternalSource;
use App\Models\GeneratedPlaylistItem;
use App\Models\Station;
use App\Services\ExternalItemPreparer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/**
 * Holt dynamische externe Inhalte (HTTP/HTTPS/FTP/FTPS) kurz vor ihrer Ausspielung herunter, prüft/misst
 * sie und legt sie als lokale Kopie ab. Der /next-Endpunkt liefert dann die vorbereitete
 * Kopie (inkl. liq_amplify) statt der externen URL – mit Direkt-Passthrough als Fallback.
 *
 * Läuft minütlich (wie EnforceHardStarts). Vorlauf & Frische steuert jede ExternalSource.
 *
 * The job is unique while it runs: the scheduler's withoutOverlapping() only guards the
 * dispatch, which takes milliseconds, so without this a run that is still downloading
 * would get a second instance on top of it every minute, fetching the same item twice.
 */
class PrepareUpcomingHttpItemsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Kulanz nach der geschätzten Sendezeit, bis ein Item nicht mehr vorbereitet wird. */
    private const PAST_GRACE_SECONDS = 60;

    /** Wait after the first failed attempt; doubles with every further one. */
    private const RETRY_BASE_SECONDS = 60;

    /** Upper bound for that wait. */
    private const RETRY_MAX_SECONDS = 900;

    /**
     * Releases the uniqueness lock even if the worker dies mid-run, so a crash cannot
     * silence the preparation for good.
     */
    public int $uniqueFor = 600;

    public function handle(ExternalItemPreparer $preparer): void
    {
        $this->cleanupStalePreparedFiles();

        // Nur Stationen mit laufendem Container betrachten.
        $stations = Station::whereHas('stream', fn ($q) => $q->where('status', 'running'))->get();

        if ($stations->isEmpty()) {
            return;
        }

        // The lead belongs to the source and is checked per item below; the largest one
        // configured bounds the query, which would otherwise load days of items to discard.
        $maxLeadSeconds = (int) ExternalSource::max('prefetch_lead_seconds');

        foreach ($stations as $station) {
            $items = GeneratedPlaylistItem::query()
                ->where('source_type', 'external')
                ->whereNotNull('external_source_id')
                ->whereNotNull('absolute_broadcast_at')
                ->where('absolute_broadcast_at', '>=', now()->subSeconds(self::PAST_GRACE_SECONDS))
                ->where('absolute_broadcast_at', '<=', now()->addSeconds($maxLeadSeconds))
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
            return $this->retryIsDue($item);
        }

        // Frische-Fenster gesetzt und überschritten → neu holen.
        return $source->freshness_seconds > 0
            && $item->prepared_at !== null
            && $item->prepared_at->lt(now()->subSeconds($source->freshness_seconds));
    }

    /**
     * Backs failed attempts off exponentially. An unreachable source is otherwise hit on
     * every tick for the whole prefetch lead, half an hour of requests per item with the
     * syndication defaults. The last resort stays: the controller still tries inline.
     */
    private function retryIsDue(GeneratedPlaylistItem $item): bool
    {
        if ($item->prepare_failed_at === null || $item->prepare_attempts < 1) {
            return true;
        }

        $waitSeconds = min(
            self::RETRY_BASE_SECONDS * (2 ** ($item->prepare_attempts - 1)),
            self::RETRY_MAX_SECONDS,
        );

        return $item->prepare_failed_at->lte(now()->subSeconds($waitSeconds));
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
