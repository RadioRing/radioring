<?php

namespace App\Console\Commands;

use App\Models\ExternalSource;
use App\Models\PlaylistItem;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('external-sources:migrate-legacy
    {--dry-run : Only show what would be converted, save nothing}')]
#[Description('Converts existing url/news/weather playlist items into reusable ExternalSource objects')]
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
            $this->info(__('No legacy items found.'));

            return self::SUCCESS;
        }

        $converted = 0;

        foreach ($items as $item) {
            $stationId = $item->playlist?->station_id;

            if (! $stationId) {
                continue;
            }

            $source = $dryRun ? null : $this->resolveSource($item, $stationId);

            $this->line(sprintf(
                '  #%s [%s] "%s" -> %s',
                $item->id,
                $item->type,
                $item->title,
                $source ? __('source #:id', ['id' => $source->id]) : __('new or reused source'),
            ));

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
        $this->info(($dryRun ? '['.__('Dry run').'] ' : '').__(':count item(s) converted.', ['count' => $converted]));

        if ($dryRun) {
            $this->warn(__('Dry run, nothing was saved.'));
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
