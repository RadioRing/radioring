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
            $sourceUpdate['expected_duration_seconds'] = $duration;
        }

        $source->update($sourceUpdate);

        return true;
    }

    /**
     * Records a failed attempt: readable on the source, counted on the item so that
     * PrepareUpcomingHttpItemsJob can back its retries off.
     */
    private function recordFailure(GeneratedPlaylistItem $item, ExternalSource $source, string $message): void
    {
        Log::warning("Externe Quelle #{$source->id} ({$source->name}): {$message}");

        $item->update([
            'prepare_attempts' => $item->prepare_attempts + 1,
            'prepare_failed_at' => now(),
        ]);

        $source->update(['last_fetched_at' => now(), 'last_error' => $message]);
    }
}
