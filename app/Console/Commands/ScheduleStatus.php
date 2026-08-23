<?php

namespace App\Console\Commands;

use App\Models\GeneratedPlaylist;
use App\Models\LiquidsoapState;
use App\Models\Station;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('radioring:schedule-status {station? : Slug or ID (default: the first station)}')]
#[Description('Shows the current playout and scheduling state of a station (debugging)')]
class ScheduleStatus extends Command
{
    public function handle(): int
    {
        $arg = $this->argument('station');
        $station = $arg
            ? Station::where('slug', $arg)->orWhere('id', is_numeric($arg) ? (int) $arg : 0)->first()
            : Station::orderBy('id')->first();

        if (! $station) {
            $this->error(__('No station found.'));

            return self::FAILURE;
        }

        $this->info("Station: #{$station->id} {$station->name} ({$station->slug})");
        $this->line(__('Now').': '.now()->toDateTimeString().'  TZ: '.config('app.timezone').'  today='.today()->toDateString().' hour='.now()->hour);
        $this->newLine();

        // Live-State
        $state = LiquidsoapState::where('station_id', $station->id)->with('nowPlayingItem')->first();
        if ($state) {
            $this->line('LiquidsoapState');
            $this->line('  current_rundown_id   : '.($state->current_rundown_id ?? '-'));
            $this->line('  current_item_position: '.$state->current_item_position.'  ('.__('pull cursor, runs ahead because of prefetching').')');
            $this->line('  now_playing_item_id  : '.($state->now_playing_item_id ?? '-').
                ($state->nowPlayingItem ? ' (Pos '.$state->nowPlayingItem->position.': '.$state->nowPlayingItem->title.')' : ''));
            $this->line('  last_pulled_at       : '.($state->last_pulled_at?->toDateTimeString() ?? '-'));
        } else {
            $this->warn(__('No LiquidsoapState present.'));
        }
        $this->newLine();

        // Today's rundowns
        $rundowns = GeneratedPlaylist::where('station_id', $station->id)
            ->where('broadcast_date', today())
            ->withCount('items')
            ->with('playlist')
            ->orderBy('broadcast_hour')
            ->get();

        $this->line(__("Today's rundowns"));
        if ($rundowns->isEmpty()) {
            $this->warn('  '.__('No rundowns for today.'));
        } else {
            $rows = $rundowns->map(fn (GeneratedPlaylist $r) => [
                sprintf('%02d:00', $r->broadcast_hour),
                $r->status,
                $r->start_mode.($r->playlist && $r->playlist->start_mode !== $r->start_mode ? ' (Playlist: '.$r->playlist->start_mode.'!)' : ''),
                $r->items_count,
                $r->id === $state?->current_rundown_id ? __('active') : '',
            ])->all();
            $this->table([__('Hour'), __('Status'), 'start_mode', __('Tracks'), ''], $rows);
        }
        $this->newLine();

        // What would /next pick right now?
        $forNow = GeneratedPlaylist::where('station_id', $station->id)
            ->where('status', 'ready')->where('broadcast_date', today())->where('broadcast_hour', now()->hour)->first();
        $this->line(__('Rundown for the current hour (ready):').' '.($forNow ? "#{$forNow->id} ".sprintf('%02d:00', $forNow->broadcast_hour) : __('none')));

        if (! $forNow) {
            $this->warn(__('There is no ready rundown for the current hour, so a hard switch cannot take hold.'));
            $this->warn('  '.__('Check status (has to be "ready"), broadcast_date (today?) and broadcast_hour (the current hour?).'));
        }

        return self::SUCCESS;
    }
}
