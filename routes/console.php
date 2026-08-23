<?php

use App\Jobs\GenerateDailyRundownsJob;
use App\Jobs\PreloadNextRundownJob;
use App\Jobs\PrepareUpcomingHttpItemsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Täglich um 22:00 – alle 24 Rundowns für den Folgetag generieren
Schedule::job(new GenerateDailyRundownsJob)->dailyAt('22:00');

// Jede Stunde um :55 – Rundown der nächsten Stunde sicherstellen (5 min Vorlauf)
Schedule::job(new PreloadNextRundownJob)->hourlyAt(55);

// Stündlich – verwaiste Upload-Chunks aufräumen
Schedule::command('media:prune-chunks')->hourly();

// Täglich – ersetzte Fassungen löschen, sobald kein Rundown mehr auf sie zeigt
Schedule::command('media:prune-replaced')->dailyAt('03:30');

// Jede Minute – Hard-Start-Rundowns zur vollen Stunde erzwingen (sample-genauer Cut)
Schedule::command('radioring:enforce-hard-starts')->everyMinute()->withoutOverlapping();

// Jede Minute – dynamische externe HTTP-Inhalte kurz vor Ausspielung holen/messen/cachen
Schedule::job(new PrepareUpcomingHttpItemsJob)->everyMinute()->withoutOverlapping();
