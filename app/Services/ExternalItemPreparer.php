<?php

namespace App\Services;

use App\Models\ExternalSource;
use App\Models\GeneratedPlaylistItem;
use App\Models\Station;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Turns an external source into a broadcast ready local copy: download, trim, measure.
 *
 * There are two moments this happens. PrepareUpcomingHttpItemsJob does it ahead of time
 * for every item whose airtime is within its source's lead, and LiquidsoapNextTrackController
 * does it inline when an item is pulled that the job has not reached yet. Both go through
 * here, so a track sounds the same whichever way it was prepared: the inline path used to
 * only store the file, leaving it untrimmed, unnormalized and unmeasured.
 */
class ExternalItemPreparer
{
    /** Below this share of the previously seen length, a prepared copy is worth a log line. */
    private const SHORTENING_ALERT_RATIO = 0.8;

    public function __construct(
        private readonly RemoteFileFetcher $fetcher,
        private readonly LoudnessAnalyzerService $analyzer,
        private readonly SilenceTrimmerService $trimmer,
        private readonly AudioMetadataService $metadata,
    ) {}

    /**
     * Prepares one item. Failures are recorded on the source (visible in the library) and
     * reported as false; the caller then skips the item rather than airing nothing.
     */
    public function prepare(GeneratedPlaylistItem $item, ExternalSource $source, Station $station, int $timeoutSeconds = 60): bool
    {
        $url = $source->resolveUrl();

        if ($url === null) {
            $this->recordFailure($item, $source, __('No address could be resolved (missing laut.fm output or credentials?).'));

            return false;
        }

        $path = "stations/{$station->slug}/prepared/{$item->id}.mp3";
        $startedAt = microtime(true);

        Log::info("External source #{$source->id} ({$source->name}): fetching for item #{$item->id} of station {$station->slug}, on air ".($item->absolute_broadcast_at?->toDateTimeString() ?? '?').'.');

        try {
            $body = $this->fetcher->fetch($url, $source->url_username, $source->url_password, $timeoutSeconds);
        } catch (RemoteFetchException $e) {
            $this->recordFailure($item, $source, $e->getMessage());

            return false;
        } catch (\Throwable $e) {
            $this->recordFailure($item, $source, __('Download failed: :error', ['error' => $e->getMessage()]));

            return false;
        }

        Storage::disk('local')->put($path, $body);

        $absolutePath = Storage::disk('local')->path($path);

        // Führende Stille offline wegschneiden (vor der Messung, damit auf dem
        // tatsächlich ausgelieferten Material gemessen wird).
        if ($source->trim_leading_silence) {
            $this->trimmer->trimLeadingSilence(
                $absolutePath,
                (float) config('radioring.silence_trim_threshold_db', -45.0),
            );
        }

        // Lautheit messen (nur wenn gewünscht) – schlägt das fehl, wird ohne Gain ausgeliefert.
        $measurement = $source->normalize ? $this->analyzer->analyze($absolutePath) : null;

        // Tatsächliche Dauer der vorbereiteten (ggf. getrimmten) Datei messen – die echte
        // Länge ist die zuverlässigste Basis für Rundown-Timing & Playlist-Planung.
        $duration = $this->metadata->read($absolutePath)['duration'];

        $item->update([
            'prepared_path' => $path,
            'prepared_at' => now(),
            'prepare_attempts' => 0,
            'prepare_failed_at' => null,
            'loudness_lufs' => $measurement['lufs'] ?? null,
            'loudness_true_peak' => $measurement['true_peak'] ?? null,
        ]);

        $sourceUpdate = [
            'last_fetched_at' => now(),
            'last_loudness_lufs' => $measurement['lufs'] ?? null,
            'last_true_peak' => $measurement['true_peak'] ?? null,
            'last_error' => null,
        ];

        if ($duration !== null && $duration > 0) {
            $this->warnAboutSuddenShortening($source, $duration);
            $sourceUpdate['expected_duration_seconds'] = $duration;
        }

        $source->update($sourceUpdate);

        Log::info(sprintf(
            'External source #%d (%s): prepared item #%d as %s, %s bytes, %s, took %.1fs.%s',
            $source->id,
            $source->name,
            $item->id,
            $path,
            number_format(strlen($body)),
            $duration !== null && $duration > 0 ? gmdate('H:i:s', (int) $duration) : __('length unknown'),
            microtime(true) - $startedAt,
            $measurement !== null ? ' '.number_format($measurement['lufs'], 1).' LUFS.' : '',
        ));

        return true;
    }

    /**
     * A file that is suddenly much shorter than what this source delivered before is the
     * signature of a transfer that ended early. RemoteFileFetcher rejects the ones that
     * announce their length, but a server can also close a stream cleanly at the wrong
     * point, and then only the duration gives it away. The copy is still used: a show
     * really can be shorter this week, and dropping it would take an hour off the air for
     * a suspicion. It is written to the log so the cause is findable afterwards.
     */
    private function warnAboutSuddenShortening(ExternalSource $source, float $duration): void
    {
        $previous = $source->expected_duration_seconds;

        if ($previous === null || $previous <= 0 || $duration >= $previous * self::SHORTENING_ALERT_RATIO) {
            return;
        }

        Log::warning(sprintf(
            'External source #%d (%s): the prepared copy is %s long, previously %s. A download that ended early looks like this.',
            $source->id,
            $source->name,
            gmdate('H:i:s', (int) $duration),
            gmdate('H:i:s', (int) $previous),
        ));
    }

    /**
     * Records a failed attempt: readable on the source, counted on the item so that
     * PrepareUpcomingHttpItemsJob can back its retries off.
     */
    private function recordFailure(GeneratedPlaylistItem $item, ExternalSource $source, string $message): void
    {
        Log::warning("External source #{$source->id} ({$source->name}): {$message} (item #{$item->id}, attempt ".($item->prepare_attempts + 1).').');

        $item->update([
            'prepare_attempts' => $item->prepare_attempts + 1,
            'prepare_failed_at' => now(),
        ]);

        $source->update(['last_fetched_at' => now(), 'last_error' => $message]);
    }
}
