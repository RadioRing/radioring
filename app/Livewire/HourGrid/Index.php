<?php

namespace App\Livewire\HourGrid;

use App\Models\GeneratedPlaylist;
use App\Models\HourGridSlot;
use App\Models\Station;
use App\Services\RundownGeneratorService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Wochenraster')]
class Index extends Component
{
    #[Locked]
    public Station $station;

    // Slot-Editor
    public ?int $editingWeekday = null;

    public ?int $editingHour = null;

    public ?int $editingPlaylistId = null;

    // Rundown-Generator
    public bool $showGeneratePanel = false;

    /** @var array<int> */
    public array $generateWeekdays = [];

    /** @var array<string> */
    public array $generateLog = [];

    public function mount(): void
    {
        $this->station = auth()->user()->currentStation()
            ?? abort(403, 'Keine Station ausgewählt.');
    }

    public function editSlot(int $weekday, int $hour): void
    {
        $this->editingWeekday = $weekday;
        $this->editingHour = $hour;

        $slot = $this->station->hourGridSlots()
            ->where('weekday', $weekday)
            ->where('hour', $hour)
            ->first();

        $this->editingPlaylistId = $slot?->playlist_id;
    }

    public function saveSlot(): void
    {
        $this->validate([
            'editingPlaylistId' => 'nullable|integer',
        ]);

        if ($this->editingPlaylistId) {
            // Sicherstellen dass die Playlist zur Station gehört
            abort_unless($this->station->playlists()->where('id', $this->editingPlaylistId)->exists(), 403);

            HourGridSlot::updateOrCreate(
                [
                    'station_id' => $this->station->id,
                    'weekday' => $this->editingWeekday,
                    'hour' => $this->editingHour,
                ],
                [
                    'playlist_id' => $this->editingPlaylistId,
                ]
            );
        } else {
            $this->station->hourGridSlots()
                ->where('weekday', $this->editingWeekday)
                ->where('hour', $this->editingHour)
                ->delete();
        }

        $this->cancelEditing();
        $this->dispatch('notify', message: __('Slot gespeichert.'), type: 'success');
    }

    public function cancelEditing(): void
    {
        $this->editingWeekday = null;
        $this->editingHour = null;
        $this->editingPlaylistId = null;
    }

    /**
     * Weist mehreren Slots gleichzeitig eine Playlist zu.
     *
     * @param  array<array{weekday: int, hour: int}>  $cells
     */
    public function assignMultiple(array $cells, int $playlistId): void
    {
        abort_unless($this->station->playlists()->where('id', $playlistId)->exists(), 403);

        foreach ($cells as $cell) {
            HourGridSlot::updateOrCreate(
                [
                    'station_id' => $this->station->id,
                    'weekday' => (int) $cell['weekday'],
                    'hour' => (int) $cell['hour'],
                ],
                ['playlist_id' => $playlistId]
            );
        }

        $this->dispatch('notify', message: __(':n Slot(s) zugewiesen.', ['n' => count($cells)]), type: 'success');
    }

    public function clearMultiple(array $cells): void
    {
        foreach ($cells as $cell) {
            $this->station->hourGridSlots()
                ->where('weekday', (int) $cell['weekday'])
                ->where('hour', (int) $cell['hour'])
                ->delete();
        }

        $this->dispatch('notify', message: __(':n Slot(s) geleert.', ['n' => count($cells)]), type: 'success');
    }

    public function generateRundownForSlot(int $weekday, int $hour): void
    {
        $slot = $this->station->hourGridSlots()
            ->where('weekday', $weekday)
            ->where('hour', $hour)
            ->firstOrFail();

        $broadcastDate = $this->broadcastDateForWeekday($weekday);

        try {
            app(RundownGeneratorService::class)->generate($this->station, $slot, $broadcastDate, true);
            $this->dispatch('notify', message: __('Rundown generiert.'), type: 'success');
        } catch (\RuntimeException $e) {
            $this->dispatch('notify', message: $e->getMessage(), type: 'error');
        }
    }

    /**
     * Generiert Rundowns für alle konfigurierten Slots der ausgewählten Wochentage.
     */
    public function generateSelected(): void
    {
        if (empty($this->generateWeekdays)) {
            return;
        }

        $service = app(RundownGeneratorService::class);
        $log = [];
        $weekdayLabels = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];

        foreach ($this->generateWeekdays as $weekday) {
            $weekday = (int) $weekday;
            $broadcastDate = $this->broadcastDateForWeekday($weekday);
            $slots = $this->station->hourGridSlots()
                ->where('weekday', $weekday)
                ->with('playlist')
                ->get();

            if ($slots->isEmpty()) {
                $log[] = ['status' => 'skip', 'text' => $weekdayLabels[$weekday].' '.$broadcastDate->format('d.m.').' – keine Slots konfiguriert'];

                continue;
            }

            foreach ($slots as $slot) {
                $label = $weekdayLabels[$weekday].' '.$broadcastDate->format('d.m.').' '.sprintf('%02d:00', $slot->hour);

                try {
                    $service->generate($this->station, $slot, $broadcastDate, true);
                    $log[] = ['status' => 'ok', 'text' => $label.' – generiert'];
                } catch (\RuntimeException $e) {
                    $log[] = ['status' => 'error', 'text' => $label.' – '.$e->getMessage()];
                }
            }
        }

        $this->generateLog = $log;
        $this->generateWeekdays = [];
    }

    public function openGeneratePanel(): void
    {
        $this->showGeneratePanel = true;
        $this->generateLog = [];
        $this->generateWeekdays = [];
    }

    public function closeGeneratePanel(): void
    {
        $this->showGeneratePanel = false;
        $this->generateLog = [];
        $this->generateWeekdays = [];
    }

    /**
     * Ermittelt das konkrete Datum für einen Wochentag (heute oder nächste Woche).
     */
    private function broadcastDateForWeekday(int $weekday): Carbon
    {
        $today = Carbon::today();
        $todayWeekday = $today->dayOfWeekIso - 1;
        $daysAhead = ($weekday - $todayWeekday + 7) % 7;

        return $today->addDays($daysAhead);
    }

    public function render()
    {
        /** @var Collection<int, HourGridSlot> $slots */
        $slots = $this->station->hourGridSlots()->with('playlist')->get();

        // $grid[weekday][hour] => HourGridSlot|null
        $grid = [];
        foreach ($slots as $slot) {
            $grid[$slot->weekday][$slot->hour] = $slot;
        }

        // Datum für jeden Wochentag ermitteln
        $weekDates = [];
        for ($d = 0; $d < 7; $d++) {
            $weekDates[$d] = $this->broadcastDateForWeekday($d)->toDateString();
        }

        // Rundown-Status für alle 7 Tage: $rundowns[weekday][hour] => status
        $placeholders = implode(',', array_fill(0, 7, '?'));
        $rundownRows = GeneratedPlaylist::where('station_id', $this->station->id)
            ->whereRaw("DATE(broadcast_date) IN ({$placeholders})", array_values($weekDates))
            ->get(['broadcast_date', 'broadcast_hour', 'status']);

        $dateToWeekday = array_flip($weekDates);
        $rundowns = [];
        foreach ($rundownRows as $row) {
            $dateKey = Carbon::parse($row->broadcast_date)->toDateString();
            $wd = $dateToWeekday[$dateKey] ?? null;
            if ($wd !== null) {
                $rundowns[$wd][$row->broadcast_hour] = $row->status;
            }
        }

        // Slot-Anzahl pro Wochentag für den Generator-Dialog
        $slotCountPerWeekday = [];
        for ($d = 0; $d < 7; $d++) {
            $slotCountPerWeekday[$d] = count($grid[$d] ?? []);
        }

        return view('livewire.hour-grid.index', [
            'grid' => $grid,
            'playlists' => $this->station->playlists()->orderBy('name')->get(),
            'rundowns' => $rundowns,
            'weekdays' => ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'],
            'weekDates' => $weekDates,
            'slotCountPerWeekday' => $slotCountPerWeekday,
        ])->layout('layouts.app');
    }
}
