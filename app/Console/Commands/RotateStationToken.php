<?php

namespace App\Console\Commands;

use App\Contracts\ContainerServiceInterface;
use App\Models\Station;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('station:rotate-token
    {station : Slug oder ID der Station}
    {--force : Ohne Rueckfrage rotieren und neu starten}
    {--no-restart : Nur den Token rotieren, Container nicht anfassen}')]
#[Description('Erzeugt einen neuen API-Token fuer eine Station und startet ihren Container neu')]
class RotateStationToken extends Command
{
    public function handle(ContainerServiceInterface $containers): int
    {
        $station = Station::query()
            ->where('slug', $this->argument('station'))
            ->orWhere('id', $this->argument('station'))
            ->first();

        if (! $station) {
            $this->error("Keine Station fuer \u{201e}{$this->argument('station')}\u{201c} gefunden.");

            return self::FAILURE;
        }

        $restart = ! $this->option('no-restart');

        if ($restart && ! $this->option('force')) {
            $containerName = $station->stream?->container_name ?? 'radioring-'.$station->slug;

            $this->warn("Der Container {$containerName} wird neu erzeugt.");
            $this->line('  Die Ausspielung unterbricht dabei kurz.');

            if (! $this->confirm('Fortfahren?', false)) {
                $this->line('Abgebrochen, nichts geaendert.');

                return self::SUCCESS;
            }
        }

        $station->update(['api_token' => Str::random(64)]);
        $this->info('Token rotiert.');

        if (! $restart) {
            $this->warn('Der laufende Container authentifiziert sich weiterhin mit dem alten Token.');
            $this->line('  Er muss neu gestartet werden, sonst bleibt die Station stumm.');

            return self::SUCCESS;
        }

        if (! $containers->isConfigured()) {
            $this->warn('Die Container-Steuerung ist nicht konfiguriert, der Neustart entfaellt.');
            $this->line('  Station im Dashboard neu starten, damit der neue Token greift.');

            return self::SUCCESS;
        }

        // Ein Restart genuegt nicht: der Token steckt als Env-Variable im Container und
        // wird nur beim Anlegen gesetzt. Der Container muss also weg und neu entstehen.
        $containers->stopStationContainer($station);

        if (! $containers->startStationContainer($station)) {
            $this->error('Der Container konnte nicht neu erzeugt werden. Siehe Logs.');

            return self::FAILURE;
        }

        $this->info('Container neu erzeugt.');

        return self::SUCCESS;
    }
}
