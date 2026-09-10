<?php

namespace App\Console\Commands;

use App\Models\Station;
use App\Services\LiquidsoapCommandService;
use App\Services\LiquidsoapStateService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Triggers the audible part of an hourly hard start on demand, so the fade out, the cut
 * and the hard start of the following element can be checked without waiting for the
 * full hour. It uses exactly the path radioring:enforce-hard-starts uses: the pull cursor
 * is placed on the element that follows, and the container gets the cut announced with a
 * lead time so it schedules the ramp itself.
 */
#[Signature('radioring:simulate-hard-cut
    {station? : Slug or ID of the station (default: the first one)}
    {--lead=10 : Seconds from now until the cut, like the lead time of a real hard start}')]
#[Description('Simulates the hourly hard cut (fade out, cut, next element) on a running station')]
class SimulateHardCut extends Command
{
    public function handle(LiquidsoapStateService $state, LiquidsoapCommandService $commands): int
    {
        $station = $this->resolveStation();

        if (! $station) {
            $this->error(__('No station found. Create a station first.'));

            return self::FAILURE;
        }

        if ($station->stream?->status !== 'running') {
            $this->error(__('Station #:station is not running, there is nothing to cut into.', ['station' => $station->id]));

            return self::FAILURE;
        }

        $lead = max(0.0, (float) $this->option('lead'));
        $fadeOut = (float) config('radioring.hard_cut_fade_out_seconds', 0.8);

        // Same cursor rewind as a manual skip: without it the prefetch would have the
        // cursor several tracks ahead, and the cut would swallow all of them.
        $state->prepareSkip($station);
        $commands->skip($station, $lead);

        $this->info(__('Hard cut simulated: station #:station, cut in :lead s, fade out :fade s before it.', [
            'station' => $station->id,
            'lead' => round($lead, 2),
            'fade' => round($fadeOut, 2),
        ]));

        $this->line('  '.__('Now playing').' : '.($station->liquidsoapState?->now_playing_title ?? '-'));

        return self::SUCCESS;
    }

    private function resolveStation(): ?Station
    {
        $arg = $this->argument('station');

        if ($arg === null) {
            return Station::orderBy('id')->first();
        }

        return Station::where('slug', $arg)
            ->orWhere('id', is_numeric($arg) ? (int) $arg : 0)
            ->first();
    }
}
