<?php

use App\Models\MediaFile;
use App\Models\Station;
use App\Models\User;
use App\Services\RundownGeneratorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->stationA = Station::factory()->create(['user_id' => $this->owner->id]);
    $this->stationB = Station::factory()->create(['user_id' => $this->owner->id]);
});

test('two stations of the same owner share one tenant', function () {
    expect($this->stationA->tenant_id)
        ->not->toBeNull()
        ->and($this->stationB->tenant_id)->toBe($this->stationA->tenant_id);
});

test('a file uploaded through one station is usable by every station of the tenant', function () {
    $file = MediaFile::factory()->create(['tenant_id' => $this->stationA->tenant_id]);

    // No linking step: the library belongs to the tenant, not the station.
    expect($this->stationB->poolMediaFiles()->pluck('id'))->toContain($file->id)
        ->and($this->stationB->canUseMedia($file))->toBeTrue();
});

test('the pool never reaches into another tenant', function () {
    $ours = MediaFile::factory()->create(['tenant_id' => $this->stationA->tenant_id]);

    $stranger = Station::factory()->create();
    $theirs = MediaFile::factory()->create(['tenant_id' => $stranger->tenant_id]);

    $poolIds = $this->stationB->poolMediaFiles()->pluck('id');

    expect($poolIds)->toContain($ours->id)
        ->and($poolIds)->not->toContain($theirs->id)
        ->and($this->stationB->canUseMedia($theirs))->toBeFalse()
        ->and($stranger->canUseMedia($ours))->toBeFalse();
});

test('an invited editor reaches the library of the station they were invited to', function () {
    $guest = User::factory()->create();
    $this->stationA->members()->attach($guest->id, ['role' => 'editor']);

    $file = MediaFile::factory()->create(['tenant_id' => $this->stationA->tenant_id]);

    expect($this->stationA->canUseMedia($file))->toBeTrue()
        ->and($guest->roleOn($this->stationA))->toBe('editor');
});

test('a user only reaches libraries of tenants they hold a station in', function () {
    // The guest owns a station in their own tenant and is invited into stationA.
    $guest = User::factory()->create();
    $guestStation = Station::factory()->create(['user_id' => $guest->id]);
    $this->stationA->members()->attach($guest->id, ['role' => 'editor']);

    $ourFile = MediaFile::factory()->create(['tenant_id' => $this->stationA->tenant_id]);
    $theirFile = MediaFile::factory()->create(['tenant_id' => $guestStation->tenant_id]);

    // Access follows the station, never the user's home tenant.
    expect($this->stationA->canUseMedia($ourFile))->toBeTrue()
        ->and($this->stationA->canUseMedia($theirFile))->toBeFalse()
        ->and($guestStation->canUseMedia($theirFile))->toBeTrue()
        ->and($guestStation->canUseMedia($ourFile))->toBeFalse();
});

test('editors may add to the library but only owners may delete', function () {
    $editor = User::factory()->create();
    $this->stationA->members()->attach($editor->id, ['role' => 'editor']);

    expect($editor->mayWriteMediaOn($this->stationA))->toBeTrue()
        ->and($editor->mayDeleteMediaOn($this->stationA))->toBeFalse()
        ->and($this->owner->mayWriteMediaOn($this->stationA))->toBeTrue()
        ->and($this->owner->mayDeleteMediaOn($this->stationA))->toBeTrue();
});

test('a stranger holds no role and may not write', function () {
    $stranger = User::factory()->create();

    expect($stranger->roleOn($this->stationA))->toBeNull()
        ->and($stranger->mayWriteMediaOn($this->stationA))->toBeFalse()
        ->and($stranger->mayDeleteMediaOn($this->stationA))->toBeFalse();
});

test('the station quota lives on the tenant and covers all its stations', function () {
    // Quotas are a cloud concept; standalone is unlimited (see AppModeTest).
    config(['radioring.mode' => 'cloud']);

    $tenant = $this->stationA->tenant;
    $tenant->update(['station_quota' => 2]);

    expect($tenant->fresh()->canCreateStation())->toBeFalse();

    $tenant->update(['station_quota' => 5]);

    expect($tenant->fresh()->canCreateStation())->toBeTrue()
        ->and($this->owner->fresh()->canCreateStation())->toBeTrue();
});

test('a fill rundown draws from the whole tenant library', function () {
    Carbon::setTestNow('2026-06-23 10:00:00');

    $fromOtherStation = MediaFile::factory()->create([
        'tenant_id' => $this->stationA->tenant_id,
        'type' => 'music',
        'duration_seconds' => 180,
    ]);

    $playlist = $this->stationB->playlists()->create(['name' => 'Fill']);
    $playlist->items()->create([
        'position' => 1,
        'title' => 'Fill block',
        'type' => 'fill',
        'fill_max_duration_seconds' => 600,
    ]);

    $slot = $this->stationB->hourGridSlots()->create([
        'weekday' => Carbon::now()->dayOfWeekIso - 1,
        'hour' => 11,
        'playlist_id' => $playlist->id,
    ]);

    $rundown = app(RundownGeneratorService::class)
        ->generate($this->stationB, $slot, Carbon::now());

    expect($rundown->items()->pluck('media_file_id'))->toContain($fromOtherStation->id);
});
