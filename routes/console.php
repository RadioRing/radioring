<?php

use App\Jobs\GenerateDailyRundownsJob;
use App\Jobs\PreloadNextRundownJob;
use App\Jobs\PrepareUpcomingHttpItemsJob;
use App\Services\Backup\BackupSettings;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Täglich um 22:00 – alle 24 Rundowns für den Folgetag generieren
Schedule::job(new GenerateDailyRundownsJob)->dailyAt('22:00')->onOneServer();

Schedule::job(new PreloadNextRundownJob)->everyFifteenMinutes()->onOneServer();

// Stündlich – verwaiste Upload-Chunks aufräumen
Schedule::command('media:prune-chunks')->hourly()->onOneServer();

// Täglich – ersetzte Fassungen löschen, sobald kein Rundown mehr auf sie zeigt
Schedule::command('media:prune-replaced')->dailyAt('03:30')->onOneServer();

// Jede Minute – Hard-Start-Rundowns zur vollen Stunde erzwingen (sample-genauer Cut)
Schedule::command('radioring:enforce-hard-starts')->everyMinute()->withoutOverlapping()->onOneServer();

// Every minute: alert mails to station owners (own process, so slow SMTP blocks nothing)
Schedule::command('radioring:check-alerts')->everyMinute()->withoutOverlapping()->runInBackground()->onOneServer();

// Jede Minute – dynamische externe HTTP-Inhalte kurz vor Ausspielung holen/messen/cachen
Schedule::job(new PrepareUpcomingHttpItemsJob)->everyMinute()->withoutOverlapping()->onOneServer();

// Hourly: ask GitHub for a newer release (or newer commits on edge). Cached, the sidebar
// only reads the stored answer.
Schedule::command('radioring:check-updates')->hourlyAt(17)->runInBackground()->onOneServer();

// Hourly check, but the report only goes out once a day and only after the admin opted in.
Schedule::command('radioring:send-telemetry')->hourlyAt(43)->runInBackground()->onOneServer();

// Nightly configuration backup. Time and retention come from the settings table, so the
// operator can change them at runtime; the schedule is rebuilt on every `schedule:run`
// and therefore picks the current value up without a redeploy.
Schedule::command('backup:run --auto')
    ->dailyAt(BackupSettings::autoTime())
    ->when(fn () => BackupSettings::autoEnabled())
    ->withoutOverlapping()
    ->onOneServer();
