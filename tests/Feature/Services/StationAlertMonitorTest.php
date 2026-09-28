<?php

use App\Enums\StationAlertType;
use App\Mail\StationAlertMail;
use App\Models\GeneratedPlaylist;
use App\Models\LiquidsoapState;
use App\Models\Setting;
use App\Models\Station;
use App\Models\StationAlert;
use App\Models\User;
use App\Services\Mail\MailSettings;
use App\Services\StationAlertMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    Setting::flushMemo();
    Mail::fake();
    config(['radioring.alerts.delay_seconds' => 120]);

    $this->founder = User::factory()->create();
    $this->station = Station::factory()->create(['user_id' => $this->founder->id, 'status' => 'active']);
    $this->station->stream()->create([
        'container_name' => 'radioring-station-'.$this->station->id,
        'status' => 'running',
    ]);
});

function sendSilence(Station $station): void
{
    LiquidsoapState::updateOrCreate(['station_id' => $station->id], [
        'underrun_started_at' => now()->subMinute(),
        'now_playing_started_at' => null,
    ]);
}

function playProgramme(Station $station): void
{
    LiquidsoapState::updateOrCreate(['station_id' => $station->id], [
        'underrun_started_at' => null,
        'underrun_logged_at' => null,
        'now_playing_title' => 'Song',
        'now_playing_source_type' => 'music',
        'now_playing_duration_seconds' => 600,
        'now_playing_started_at' => now(),
    ]);
}

function runMonitor(): void
{
    app(StationAlertMonitor::class)->check();
}

test('an off air station is only reported once the delay has passed', function () {
    sendSilence($this->station);

    runMonitor();

    Mail::assertNothingSent();
    expect(StationAlert::sole()->type)->toBe(StationAlertType::Silence);

    $this->travel(3)->minutes();
    runMonitor();

    Mail::assertSent(StationAlertMail::class, fn (StationAlertMail $mail) => $mail->hasTo($this->founder->email) && ! $mail->resolved);
});

test('every episode is mailed once, not on every run', function () {
    sendSilence($this->station);
    runMonitor();

    $this->travel(3)->minutes();
    runMonitor();
    runMonitor();

    $this->travel(10)->minutes();
    runMonitor();

    Mail::assertSentCount(1);
});

test('owners are mailed, editors are not', function () {
    $owner = User::factory()->create();
    $editor = User::factory()->create();
    $this->station->members()->attach($owner->id, ['role' => 'owner']);
    $this->station->members()->attach($editor->id, ['role' => 'editor']);

    sendSilence($this->station);
    runMonitor();
    $this->travel(3)->minutes();
    runMonitor();

    Mail::assertSent(StationAlertMail::class, fn (StationAlertMail $mail) => $mail->hasTo($this->founder->email));
    Mail::assertSent(StationAlertMail::class, fn (StationAlertMail $mail) => $mail->hasTo($owner->email));
    Mail::assertNotSent(StationAlertMail::class, fn (StationAlertMail $mail) => $mail->hasTo($editor->email));
    Mail::assertSentCount(2);
});

test('owners who opted out are not mailed', function () {
    $this->founder->update(['receives_alert_emails' => false]);

    sendSilence($this->station);
    runMonitor();
    $this->travel(3)->minutes();
    runMonitor();

    Mail::assertNothingSent();
    expect(StationAlert::sole()->notified_at)->not->toBeNull();
});

test('the owners get an all-clear once the station is back on air', function () {
    sendSilence($this->station);
    runMonitor();
    $this->travel(3)->minutes();
    runMonitor();

    playProgramme($this->station);
    runMonitor();

    Mail::assertSent(StationAlertMail::class, fn (StationAlertMail $mail) => $mail->resolved);
    Mail::assertSentCount(2);

    $alert = StationAlert::sole();
    expect($alert->resolved_at)->not->toBeNull()
        ->and($alert->resolved_notified_at)->not->toBeNull();
});

test('a gap shorter than the delay leaves no trace', function () {
    sendSilence($this->station);
    runMonitor();

    playProgramme($this->station);
    runMonitor();

    Mail::assertNothingSent();
    expect(StationAlert::count())->toBe(0);
});

test('a stopped station is not watched, and its open alert closes silently', function () {
    sendSilence($this->station);
    runMonitor();
    $this->travel(3)->minutes();
    runMonitor();

    $this->station->stream()->update(['status' => 'stopped']);
    runMonitor();

    Mail::assertSentCount(1);
    expect(StationAlert::sole()->resolved_at)->not->toBeNull();
});

test('a station with alert mails switched off is not watched', function () {
    $this->station->update(['alert_emails_enabled' => false]);

    sendSilence($this->station);
    runMonitor();
    $this->travel(3)->minutes();
    runMonitor();

    Mail::assertNothingSent();
    expect(StationAlert::count())->toBe(0);
});

test('the emergency loop taking over from silence is no all-clear', function () {
    sendSilence($this->station);
    runMonitor();
    $this->travel(3)->minutes();
    runMonitor();

    LiquidsoapState::where('station_id', $this->station->id)->update([
        'now_playing_source_type' => 'emergency',
        'now_playing_duration_seconds' => 180,
        'now_playing_started_at' => now(),
    ]);
    runMonitor();

    Mail::assertNotSent(StationAlertMail::class, fn (StationAlertMail $mail) => $mail->resolved);
    expect(StationAlert::open()->sole()->type)->toBe(StationAlertType::EmergencyLoop);
});

test('a running container that reports nothing is reported', function () {
    $this->station->stream()->update(['last_started_at' => now()->subMinutes(10)]);

    runMonitor();

    expect(StationAlert::sole()->type)->toBe(StationAlertType::NoPlayout);
});

test('a container that was just started gets time to report its first track', function () {
    $this->station->stream()->update(['last_started_at' => now()->subSeconds(30)]);

    runMonitor();

    expect(StationAlert::count())->toBe(0);
});

test('a missing rundown for the hour on air is reported', function () {
    playProgramme($this->station);
    $playlist = $this->station->playlists()->create(['name' => 'Show', 'playback_mode' => 'sequential']);
    $slot = $this->station->hourGridSlots()->create([
        'weekday' => now()->dayOfWeekIso - 1,
        'hour' => now()->hour,
        'playlist_id' => $playlist->id,
    ]);

    runMonitor();

    expect(StationAlert::sole()->type)->toBe(StationAlertType::RundownMissing);

    GeneratedPlaylist::create([
        'station_id' => $this->station->id,
        'hour_grid_slot_id' => $slot->id,
        'playlist_id' => $playlist->id,
        'broadcast_date' => today()->toDateString(),
        'broadcast_hour' => now()->hour,
        'status' => 'ready',
        'generated_at' => now(),
    ]);
    runMonitor();

    expect(StationAlert::count())->toBe(0);
});

test('station mails use the station sender when it is switched on', function () {
    MailSettings::store([
        ...MailSettings::values(),
        'station_sender' => true,
        'station_sender_domain' => 'radio.example',
    ]);

    $mail = new StationAlertMail($this->station, StationAlert::factory()->for($this->station)->create());

    $mail->assertFrom($this->station->slug.'-noreply@radio.example');
});

test('without the station sender the general sender applies', function () {
    $mail = new StationAlertMail($this->station, StationAlert::factory()->for($this->station)->create());

    expect($mail->envelope()->from)->toBeNull();
});

test('the alert mail renders', function () {
    $alert = StationAlert::factory()->for($this->station)->create(['type' => StationAlertType::EmergencyLoop]);

    (new StationAlertMail($this->station, $alert))
        ->assertSeeInHtml($this->station->name)
        ->assertSeeInHtml(StationAlertType::EmergencyLoop->label());
});
