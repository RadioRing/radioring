<?php

namespace App\Console\Commands;

use App\Models\GeneratedPlaylist;
use App\Models\LiquidsoapState;
use App\Models\Station;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('radioring:schedule-status {station? : Slug oder ID (Standard: erste Station)}')]
#[Description('Zeigt den aktuellen Abspiel-/Scheduling-Zustand einer Station (Debugging)')]
class ScheduleStatus extends Command
{
    public function handle(): int
    {
        $arg = $this->argument('station');
        $station = $arg
            ? Station::where('slug', $arg)->orWhere('id', is_numeric($arg) ? (int) $arg : 0)->first()
            : Station::orderBy('id')->first();

        if (! $station) {
            $this->error('Keine Station gefunden.');

            return self::FAILURE;
        }

        $this->info("Station: #{$station->id} {$station->name} ({$station->slug})");
        $this->line('Jetzt: '.now()->toDateTimeString().'  TZ: '.config('app.timezone').'  → today='.today()->toDateString().' hour='.now()->hour);
        $this->newLine();

        // Live-State
        $state = LiquidsoapState::where('station_id', $station->id)->with('nowPlayingItem')->first();
        if ($state) {
            $this->line('LiquidsoapState');
            $this->line('  current_rundown_id   : '.($state->current_rundown_id ?? '–'));
            $this->line('  current_item_position: '.$state->current_item_position.'  (Pull-Cursor, eilt durch Prefetch voraus)');
            $this->line('  now_playing_item_id  : '.($state->now_playing_item_id ?? '–').
                ($state->nowPlayingItem ? ' (Pos '.$state->nowPlayingItem->position.': '.$state->nowPlayingItem->title.')' : ''));
            $this->line('  last_pulled_at       : '.($state->last_pulled_at?->toDateTimeString() ?? '–'));
        } else {
            $this->warn('Kein LiquidsoapState vorhanden.');
        }
        $this->newLine();

        // Heutige Rundowns
        $rundowns = GeneratedPlaylist::where('station_id', $station->id)
            ->where('broadcast_date', today())
            ->withCount('items')
            ->with('playlist')
            ->orderBy('broadcast_hour')
            ->get();

        $this->line('Rundowns heute');
        if ($rundowns->isEmpty()) {
            $this->warn('  Keine Rundowns für heute.');
        } else {
            $rows = $rundowns->map(fn (GeneratedPlaylist $r) => [
                sprintf('%02d:00', $r->broadcast_hour),
                $r->status,
                $r->start_mode.($r->playlist && $r->playlist->start_mode !== $r->start_mode ? ' (Playlist: '.$r->playlist->start_mode.'!)' : ''),
                $r->items_count,
                $r->id === $state?->current_rundown_id ? '◀ aktiv' : '',
            ])->all();
            $this->table(['Stunde', 'Status', 'start_mode', 'Tracks', ''], $rows);
        }
        $this->newLine();

        // Was würde /next jetzt wählen?
        $forNow = GeneratedPlaylist::where('station_id', $station->id)
            ->where('status', 'ready')->where('broadcast_date', today())->where('broadcast_hour', now()->hour)->first();
        $this->line('Rundown für aktuelle Stunde (ready): '.($forNow ? "#{$forNow->id} ".sprintf('%02d:00', $forNow->broadcast_hour) : '– keiner'));

        if (! $forNow) {
            $this->warn('→ Für die jetzige Stunde gibt es keinen ready-Rundown. Hard-Switch kann nicht greifen.');
            $this->warn('  Prüfe: Status (muss "ready" sein), broadcast_date (=heute?), broadcast_hour (=jetzige Stunde?).');
        }

        return self::SUCCESS;
    }
}
