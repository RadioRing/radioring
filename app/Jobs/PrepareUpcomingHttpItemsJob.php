<?php

namespace App\Jobs;

use App\Models\ExternalSource;
use App\Models\GeneratedPlaylistItem;
use App\Models\LiquidsoapState;
use App\Models\Station;
use App\Services\ExternalItemPreparer;
use App\Services\LiquidsoapStateService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
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

    /** Kulanz nach der Ausstrahlung, bis eine vorbereitete Kopie gelöscht wird. */
    private const KEEP_AFTER_AIRTIME_SECONDS = 3600;

    private const FORGET_AFTER_AIRTIME_SECONDS = 86400;

    /**
     * How far down the programme the pull cursor is followed. Liquidsoap resolves three
     * requests ahead (prefetch=3 in the generated script); the margin covers elements that
     * are skipped on the way and a hard start that reshuffles what comes next.
     */
    private const PULL_HORIZON_ITEMS = 10;

    /** Wait after the first failed attempt; doubles with every further one. */
    private const RETRY_BASE_SECONDS = 60;

    /** Upper bound for that wait. */
    private const RETRY_MAX_SECONDS = 900;

    /**
     * Releases the uniqueness lock even if the worker dies mid-run, so a crash cannot
     * silence the preparation for good.
     */
    public int $uniqueFor = 600;

    public function handle(ExternalItemPreparer $preparer, LiquidsoapStateService $stateService): void
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
            $horizon = $this->itemsWithinPullHorizon($station, $stateService);
            $horizonIds = $horizon->pluck('id')->all();

            foreach ($this->itemsNearAirtime($station, $maxLeadSeconds)->merge($horizon)->unique('id') as $item) {
                $source = $item->externalSource;

                if (! $source) {
                    continue;
                }

                // Two reasons to prepare an element, and it needs one of them: its airtime
                // is within the source's lead, or the cursor is about to reach it.
                if (! $this->isDue($item, $source) && ! in_array($item->id, $horizonIds, true)) {
                    continue;
                }

                if (! $this->needsPreparing($item, $source)) {
                    continue;
                }

                $preparer->prepare($item, $source, $station);
            }
        }
    }

    /**
     * External items of this station whose planned airtime falls into the widest lead any
     * source has configured. Each one is checked against its own source afterwards.
     *
     * @return Collection<int, GeneratedPlaylistItem>
     */
    private function itemsNearAirtime(Station $station, int $maxLeadSeconds): Collection
    {
        return GeneratedPlaylistItem::query()
            ->where('source_type', 'external')
            ->whereNotNull('external_source_id')
            ->whereNotNull('absolute_broadcast_at')
            ->where('absolute_broadcast_at', '>=', now()->subSeconds(self::PAST_GRACE_SECONDS))
            ->where('absolute_broadcast_at', '<=', now()->addSeconds($maxLeadSeconds))
            ->whereHas('generatedPlaylist', fn ($q) => $q->where('station_id', $station->id)->where('status', 'ready'))
            ->with('externalSource')
            ->get();
    }

    /**
     * External items the pull cursor is about to reach, whatever their airtime says.
     *
     * @return Collection<int, GeneratedPlaylistItem>
     */
    private function itemsWithinPullHorizon(Station $station, LiquidsoapStateService $stateService): Collection
    {
        return $stateService->upcomingItems($station, self::PULL_HORIZON_ITEMS)
            ->where('source_type', 'external')
            ->whereNotNull('external_source_id')
            ->each(fn (GeneratedPlaylistItem $item) => $item->loadMissing('externalSource'));
    }

    /** Liegt die geschätzte Sendezeit innerhalb des Quellen-Vorlaufs? */
    private function isDue(GeneratedPlaylistItem $item, ExternalSource $source): bool
    {
        if ($item->absolute_broadcast_at === null) {
            return false;
        }

        return $item->absolute_broadcast_at->lte(now()->addSeconds($source->prefetch_lead_seconds));
    }

    private function needsPreparing(GeneratedPlaylistItem $item, ExternalSource $source): bool
    {
        // Noch nie vorbereitet oder Datei verschwunden → vorbereiten.
        if (! $item->prepared_path || ! Storage::disk('local')->exists($item->prepared_path)) {
            return $this->retryIsDue($item);
        }

        // Frische-Fenster gesetzt und überschritten → neu holen, auch weit vor der Sendezeit.
        // Ein Element, das lange im Abrufhorizont liegt, hält sich damit selbst aktuell, bis
        // der Container es zieht. Jede Erneuerung setzt prepared_at, die nächste kommt also
        // frühestens ein Frische-Fenster später.
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
     * Removes cached items so drive doesn't fill up.
     */
    private function cleanupStalePreparedFiles(): void
    {
        $grace = now()->subSeconds(self::KEEP_AFTER_AIRTIME_SECONDS);

        $items = GeneratedPlaylistItem::query()
            ->whereNotNull('prepared_path')
            ->where(function ($query) use ($grace) {
                // An element without a fixed timestamp has no planned time. Its copy is
                // still ours to clean up, so the age of the copy stands in for it.
                $query->where('absolute_broadcast_at', '<', $grace)
                    ->orWhere(fn ($q) => $q->whereNull('absolute_broadcast_at')->where('prepared_at', '<', $grace));
            })
            ->with('generatedPlaylist')
            ->get();

        if ($items->isEmpty()) {
            return;
        }

        $cursors = LiquidsoapState::whereIn(
            'station_id',
            $items->pluck('generatedPlaylist.station_id')->filter()->unique(),
        )->get()->keyBy('station_id');

        foreach ($items as $item) {
            if ($this->isStillAhead($item, $cursors)) {
                continue;
            }

            Storage::disk('local')->delete($item->prepared_path);
            $item->update(['prepared_path' => null]);
        }
    }

    /**
     * Is this element still to be handed out, whatever its planned time says?
     *
     * @param  Collection<int, LiquidsoapState>  $cursors  keyed by station
     */
    private function isStillAhead(GeneratedPlaylistItem $item, Collection $cursors): bool
    {
        $rundown = $item->generatedPlaylist;

        if (! $rundown || $rundown->status === 'played') {
            return false;
        }

        // Nobody is going to ask for this any more, whatever the cursor says.
        $age = $item->absolute_broadcast_at ?? $item->prepared_at;

        if ($age !== null && $age->lt(now()->subSeconds(self::FORGET_AFTER_AIRTIME_SECONDS))) {
            return false;
        }

        $state = $cursors->get($rundown->station_id);

        if (! $state || $state->current_rundown_id !== $rundown->id) {
            // A rundown the cursor has left behind is marked played by the now-playing
            // callback, so what is left here is one it has not reached yet.
            return true;
        }

        return $item->position >= $state->current_item_position;
    }
}
