<?php

namespace App\Console\Commands;

use App\Models\MediaFile;
use App\Models\Station;
use App\Services\AudioMetadataService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('media:rescan-tags
    {--station= : Nur die Bibliothek des Mandanten dieser Station (ID oder Slug)}
    {--force : Vorhandene Werte überschreiben statt nur leere Felder zu füllen}
    {--dry-run : Nur anzeigen, was geändert würde – nichts speichern}')]
#[Description('Liest die ID3-Tags vorhandener Mediendateien neu und füllt leere DB-Felder (Interpret, Album, Titel, Dauer)')]
class RescanMediaTags extends Command
{
    public function handle(AudioMetadataService $metadata): int
    {
        $query = MediaFile::query();

        if ($this->option('station') !== null) {
            $station = Station::where('id', $this->option('station'))
                ->orWhere('slug', $this->option('station'))
                ->first();

            if (! $station) {
                $this->error('Keine Station für „'.$this->option('station').'" gefunden.');

                return self::FAILURE;
            }

            // Media belongs to the tenant, so scoping by station means its tenant's library.
            $query->where('tenant_id', $station->tenant_id);
        }

        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');
        $disk = Storage::disk('local');

        $scanned = 0;
        $updated = 0;
        $unchanged = 0;
        $missing = 0;

        $query->orderBy('id')->chunkById(100, function ($files) use ($metadata, $disk, $force, $dryRun, &$scanned, &$updated, &$unchanged, &$missing) {
            foreach ($files as $file) {
                $scanned++;

                if (! $disk->exists($file->file_path)) {
                    $missing++;
                    $this->warn("  fehlt auf Disk: {$file->file_path}");

                    continue;
                }

                $meta = $metadata->read($disk->path($file->file_path));
                $changes = $this->changesFor($file, $meta, $force);

                if ($changes === []) {
                    $unchanged++;

                    continue;
                }

                if (! $dryRun) {
                    $file->fill($changes)->save();
                }

                $updated++;
                $this->line("  #{$file->id} {$file->title}: ".$this->describe($changes));
            }
        });

        $this->newLine();
        $this->table(['Gescannt', 'Aktualisiert', 'Unverändert', 'Fehlend'], [
            [$scanned, $updated, $unchanged, $missing],
        ]);

        if ($dryRun) {
            $this->info('Probelauf – es wurde nichts gespeichert.');
        }

        return self::SUCCESS;
    }

    /**
     * Ermittelt die zu setzenden Felder: gefüllt wird nur, wenn das Feld leer ist
     * (oder --force gesetzt) und der ID3-Tag tatsächlich einen Wert liefert.
     *
     * @param  array{title: ?string, artist: ?string, album: ?string, duration: ?int}  $meta
     * @return array<string, mixed>
     */
    private function changesFor(MediaFile $file, array $meta, bool $force): array
    {
        $changes = [];

        foreach (['title', 'artist', 'album'] as $column) {
            $value = $meta[$column] ?? null;

            if ($value !== null && $value !== '' && ($force || blank($file->{$column}))) {
                $changes[$column] = $value;
            }
        }

        if ($meta['duration'] !== null && ($force || $file->duration_seconds === null)) {
            $changes['duration_seconds'] = $meta['duration'];
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function describe(array $changes): string
    {
        return collect($changes)
            ->map(fn ($value, $key) => "{$key}=\"{$value}\"")
            ->implode(', ');
    }
}
