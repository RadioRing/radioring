<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces start_mode and element timestamps with marker elements.
 *
 * - hard start playlist: hard 00:00 marker on top
 * - element timestamp: soft marker in front of the element
 * - container timestamps: dropped (never applied)
 * - generated hard rundowns: hard fixed time on the first item
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('playlist_items', function (Blueprint $table) {
            // soft or hard, markers only.
            $table->string('fixed_mode', 8)->nullable()->after('relative_offset_seconds');
        });

        Schema::table('liquidsoap_states', function (Blueprint $table) {
            // Last announced or made hard cut.
            $table->dateTime('committed_hard_time')->nullable()->after('current_item_position');
        });

        $this->convertPlaylists();
        $this->convertGeneratedRundowns();
        $this->convertCommittedHardStarts();

        Schema::table('liquidsoap_states', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hard_start_committed_rundown_id');
        });

        Schema::table('generated_playlists', function (Blueprint $table) {
            $table->dropColumn('start_mode');
        });

        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn('start_mode');
        });
    }

    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->string('start_mode')->default('soft')->after('playback_mode');
        });

        Schema::table('generated_playlists', function (Blueprint $table) {
            $table->string('start_mode')->default('soft')->after('playlist_id');
        });

        Schema::table('liquidsoap_states', function (Blueprint $table) {
            $table->foreignId('hard_start_committed_rundown_id')
                ->nullable()
                ->after('current_item_position')
                ->constrained('generated_playlists')
                ->nullOnDelete();
        });

        // Hard 00:00 marker on top: start_mode hard; other markers: offset back to the next element.
        foreach (DB::table('playlists')->pluck('id') as $playlistId) {
            $items = DB::table('playlist_items')->where('playlist_id', $playlistId)->orderBy('position')->orderBy('id')->get();
            $pendingOffset = null;

            foreach ($items as $index => $item) {
                if ($item->type !== 'marker') {
                    if ($pendingOffset !== null) {
                        DB::table('playlist_items')->where('id', $item->id)->update(['relative_offset_seconds' => $pendingOffset]);
                        $pendingOffset = null;
                    }

                    continue;
                }

                if ($index === 0 && $item->fixed_mode === 'hard' && (int) $item->relative_offset_seconds === 0) {
                    DB::table('playlists')->where('id', $playlistId)->update(['start_mode' => 'hard']);
                } else {
                    $pendingOffset = $item->relative_offset_seconds;
                }

                DB::table('playlist_items')->where('id', $item->id)->delete();
            }
        }

        $hardRundownIds = DB::table('generated_playlist_items')
            ->where('position', 0)
            ->where('fixed_mode', 'hard')
            ->pluck('generated_playlist_id');

        DB::table('generated_playlists')->whereIn('id', $hardRundownIds)->update(['start_mode' => 'hard']);

        Schema::table('liquidsoap_states', function (Blueprint $table) {
            $table->dropColumn('committed_hard_time');
        });

        Schema::table('playlist_items', function (Blueprint $table) {
            $table->dropColumn('fixed_mode');
        });
    }

    private function convertPlaylists(): void
    {
        $now = now();

        foreach (DB::table('playlists')->get(['id', 'kind', 'start_mode']) as $playlist) {
            if ($playlist->kind === 'container') {
                DB::table('playlist_items')
                    ->where('playlist_id', $playlist->id)
                    ->whereNotNull('relative_offset_seconds')
                    ->update(['relative_offset_seconds' => null]);

                continue;
            }

            $items = DB::table('playlist_items')->where('playlist_id', $playlist->id)->orderBy('position')->orderBy('id')->get();
            $position = 0;

            $insertMarker = function (int $offset, string $mode) use ($playlist, &$position, $now): void {
                DB::table('playlist_items')->insert([
                    'playlist_id' => $playlist->id,
                    'position' => $position++,
                    'type' => 'marker',
                    'title' => 'Fixzeit',
                    'relative_offset_seconds' => $offset,
                    'fixed_mode' => $mode,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            };

            if ($playlist->start_mode === 'hard') {
                $insertMarker(0, 'hard');
            }

            foreach ($items as $item) {
                $changes = [];

                if ($item->relative_offset_seconds !== null) {
                    if (! in_array($item->type, ['fill', 'random'], true)) {
                        $insertMarker((int) $item->relative_offset_seconds, 'soft');
                    }

                    $changes['relative_offset_seconds'] = null;
                }

                if ((int) $item->position !== $position) {
                    $changes['position'] = $position;
                }

                $position++;

                if ($changes !== []) {
                    DB::table('playlist_items')->where('id', $item->id)->update($changes);
                }
            }
        }
    }

    private function convertGeneratedRundowns(): void
    {
        $rundowns = DB::table('generated_playlists')->where('start_mode', 'hard')->get(['id', 'broadcast_date', 'broadcast_hour']);

        foreach ($rundowns as $rundown) {
            DB::table('generated_playlist_items')
                ->where('generated_playlist_id', $rundown->id)
                ->where('position', 0)
                ->update([
                    'fixed_at' => Carbon::parse($rundown->broadcast_date)->setTime((int) $rundown->broadcast_hour, 0, 0),
                    'fixed_mode' => 'hard',
                ]);
        }
    }

    private function convertCommittedHardStarts(): void
    {
        $states = DB::table('liquidsoap_states')
            ->join('generated_playlists', 'generated_playlists.id', '=', 'liquidsoap_states.hard_start_committed_rundown_id')
            ->get(['liquidsoap_states.id', 'generated_playlists.broadcast_date', 'generated_playlists.broadcast_hour']);

        foreach ($states as $state) {
            DB::table('liquidsoap_states')->where('id', $state->id)->update([
                'committed_hard_time' => Carbon::parse($state->broadcast_date)->setTime((int) $state->broadcast_hour, 0, 0),
            ]);
        }
    }
};
