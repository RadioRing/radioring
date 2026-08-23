<?php

namespace App\Jobs;

use App\Models\ExternalSource;
use App\Models\GeneratedPlaylistItem;
use App\Models\Station;
use App\Services\AudioMetadataService;
use App\Services\LoudnessAnalyzerService;
use App\Services\SilenceTrimmerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Holt dynamische externe HTTP-Inhalte kurz vor ihrer Ausspielung herunter, prüft/misst
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

    public function handle(LoudnessAnalyzerService $analyzer, SilenceTrimmerService $trimmer, AudioMetadataService $metadata): void
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

                $this->prepare($item, $source, $station, $analyzer, $trimmer, $metadata);
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

    private function prepare(GeneratedPlaylistItem $item, ExternalSource $source, Station $station, LoudnessAnalyzerService $analyzer, SilenceTrimmerService $trimmer, AudioMetadataService $metadata): void
    {
        $url = $source->resolveUrl();

        if ($url === null) {
            $this->recordError($source, 'Keine URL auflösbar (laut.fm-Ausgang/Credentials fehlen?).');

            return;
        }

        $path = "stations/{$station->slug}/prepared/{$item->id}.mp3";

        try {
            $response = Http::timeout(60)->get($url);

            if (! $response->successful() || $response->body() === '') {
                $this->recordError($source, 'Download fehlgeschlagen (HTTP '.$response->status().').');

                return;
            }

            Storage::disk('local')->put($path, $response->body());
        } catch (\Throwable $e) {
            $this->recordError($source, 'Download-Fehler: '.$e->getMessage());

            return;
        }

        // Führende Stille offline wegschneiden (vor der Messung, damit auf dem
        // tatsächlich ausgelieferten Material gemessen wird).
        if ($source->trim_leading_silence) {
            $trimmer->trimLeadingSilence(
                Storage::disk('local')->path($path),
                (float) config('radioring.silence_trim_threshold_db', -45.0),
            );
        }

        // Lautheit messen (nur wenn gewünscht) – schlägt das fehl, wird ohne Gain ausgeliefert.
        $measurement = $source->normalize ? $analyzer->analyze(Storage::disk('local')->path($path)) : null;

        // Tatsächliche Dauer der vorbereiteten (ggf. getrimmten) Datei messen – die echte
        // Länge ist die zuverlässigste Basis für Rundown-Timing & Playlist-Planung.
        $duration = $metadata->read(Storage::disk('local')->path($path))['duration'];

        $item->update([
            'prepared_path' => $path,
            'prepared_at' => now(),
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
    }

    private function recordError(ExternalSource $source, string $message): void
    {
        Log::warning("Externe Quelle #{$source->id} ({$source->name}): {$message}");

        $source->update(['last_fetched_at' => now(), 'last_error' => $message]);
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
