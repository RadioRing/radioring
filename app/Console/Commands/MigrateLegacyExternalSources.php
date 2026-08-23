<?php

namespace App\Console\Commands;

use App\Models\ExternalSource;
use App\Models\PlaylistItem;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('external-sources:migrate-legacy
    {--dry-run : Nur anzeigen, was umgewandelt würde – nichts speichern}')]
#[Description('Wandelt bestehende url/news/weather-Playlist-Items in wiederverwendbare ExternalSource-Objekte um')]
class MigrateLegacyExternalSources extends Command
{
    private const TITLES = [
        'news' => 'Nachrichten (laut.fm)',
        'weather' => 'Wetter (laut.fm)',
        'news_weather' => 'Nachrichten + Wetter (laut.fm)',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $items = PlaylistItem::query()
            ->whereIn('type', ['url', 'news', 'weather', 'news_weather'])
            ->with('playlist:id,station_id')
            ->get();

        if ($items->isEmpty()) {
            $this->info('Keine Legacy-Items gefunden.');

            return self::SUCCESS;
        }

        $converted = 0;

        foreach ($items as $item) {
            $stationId = $item->playlist?->station_id;

            if (! $stationId) {
                continue;
            }

            $source = $dryRun ? null : $this->resolveSource($item, $stationId);

            $this->line("  #{$item->id} [{$item->type}] „{$item->title}\" → ".
                ($source ? "Quelle #{$source->id}" : 'neue/wiederverwendete Quelle'));

            if (! $dryRun) {
                DB::transaction(function () use ($item, $source) {
                    $item->update([
                        'type' => 'external',
                        'external_source_id' => $source->id,
                        'url' => null,
                    ]);
                });
            }

            $converted++;
        }

        $this->newLine();
        $this->info(($dryRun ? '[Probelauf] ' : '')."{$converted} Item(s) umgewandelt.");

        if ($dryRun) {
            $this->warn('Probelauf – es wurde nichts gespeichert.');
        }

        return self::SUCCESS;
    }

    /**
     * Findet eine passende vorhandene Quelle oder legt sie an. URL-Items werden je
     * eindeutiger URL zusammengefasst; News/Wetter je Station und Art.
     */
    private function resolveSource(PlaylistItem $item, int $stationId): ExternalSource
    {
        if ($item->type === 'url') {
            return ExternalSource::firstOrCreate(
                ['station_id' => $stationId, 'kind' => 'url', 'url' => $item->url],
                [
                    'name' => $item->title ?: 'Externe URL',
                    'expected_duration_seconds' => $item->duration_seconds,
                ],
            );
        }

        return ExternalSource::firstOrCreate(
            ['station_id' => $stationId, 'kind' => $item->type],
            ['name' => self::TITLES[$item->type], 'url' => null],
        );
    }
}
