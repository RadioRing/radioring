<?php

use App\Models\MediaFile;
use App\Models\Station;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Builds a file with the given windows. 2026-09-14 is a Monday, which every time in
 * this file refers to.
 */
function gatedFile(array $windows, ?int $tenantId = null): MediaFile
{
    return MediaFile::factory()->create([
        'tenant_id' => $tenantId ?? Station::factory()->create()->tenant_id,
        'airtime_windows' => $windows,
    ]);
}

test('a file without windows airs at any time', function () {
    $file = MediaFile::factory()->create(['airtime_windows' => null]);

    expect($file->isAirableAt(Carbon\Carbon::parse('2026-09-14 03:00')))->toBeTrue();
});

test('a window limits the file to its weekdays and hours', function () {
    $file = gatedFile([['days' => [1, 2, 3, 4, 5], 'from' => '06:00', 'to' => '10:00']]);

    expect($file->isAirableAt(Carbon\Carbon::parse('2026-09-14 07:30')))->toBeTrue();
    expect($file->isAirableAt(Carbon\Carbon::parse('2026-09-14 05:59')))->toBeFalse();
    expect($file->isAirableAt(Carbon\Carbon::parse('2026-09-14 10:00')))->toBeFalse();
    // Saturday is not in the list.
    expect($file->isAirableAt(Carbon\Carbon::parse('2026-09-19 07:30')))->toBeFalse();
});

test('a window without weekdays counts for every day', function () {
    $file = gatedFile([['days' => [], 'from' => '06:00', 'to' => '10:00']]);

    expect($file->isAirableAt(Carbon\Carbon::parse('2026-09-14 07:00')))->toBeTrue();
    expect($file->isAirableAt(Carbon\Carbon::parse('2026-09-19 07:00')))->toBeTrue();
    expect($file->isAirableAt(Carbon\Carbon::parse('2026-09-19 11:00')))->toBeFalse();
});

test('several windows are combined with or', function () {
    $file = gatedFile([
        ['days' => [1], 'from' => '06:00', 'to' => '10:00'],
        ['days' => [6, 7], 'from' => '08:00', 'to' => '11:00'],
    ]);

    expect($file->isAirableAt(Carbon\Carbon::parse('2026-09-14 07:00')))->toBeTrue();
    expect($file->isAirableAt(Carbon\Carbon::parse('2026-09-19 09:00')))->toBeTrue();
    expect($file->isAirableAt(Carbon\Carbon::parse('2026-09-19 07:00')))->toBeFalse();
});

test('a window running past midnight reaches into the next day', function () {
    // Saturday night, 22:00 until 02:00 on Sunday.
    $file = gatedFile([['days' => [6], 'from' => '22:00', 'to' => '02:00']]);

    expect($file->isAirableAt(Carbon\Carbon::parse('2026-09-19 23:30')))->toBeTrue();
    expect($file->isAirableAt(Carbon\Carbon::parse('2026-09-20 01:30')))->toBeTrue();
    expect($file->isAirableAt(Carbon\Carbon::parse('2026-09-20 02:30')))->toBeFalse();
    // Sunday evening is not covered: the window starts on Saturday.
    expect($file->isAirableAt(Carbon\Carbon::parse('2026-09-20 23:30')))->toBeFalse();
});

test('equal bounds describe a whole day', function () {
    $file = gatedFile([['days' => [1], 'from' => '00:00', 'to' => '00:00']]);

    expect($file->isAirableAt(Carbon\Carbon::parse('2026-09-14 13:00')))->toBeTrue();
    expect($file->isAirableAt(Carbon\Carbon::parse('2026-09-15 13:00')))->toBeFalse();
});

test('the query scope drops only the files blocked at that moment', function () {
    $station = Station::factory()->create();

    $always = MediaFile::factory()->create(['tenant_id' => $station->tenant_id, 'airtime_windows' => null]);
    $morning = gatedFile([['days' => [], 'from' => '06:00', 'to' => '10:00']], $station->tenant_id);

    $inWindow = $station->poolMediaFiles()
        ->airableAt(Carbon\Carbon::parse('2026-09-14 07:00'))
        ->pluck('id');

    expect($inWindow)->toContain($always->id)->toContain($morning->id);

    $outsideWindow = $station->poolMediaFiles()
        ->airableAt(Carbon\Carbon::parse('2026-09-14 14:00'))
        ->pluck('id');

    expect($outsideWindow)->toContain($always->id)->not->toContain($morning->id);
});

test('the summary names the days and times', function () {
    $file = gatedFile([['days' => [1, 2], 'from' => '06:00', 'to' => '10:00']]);

    expect($file->airtimeWindowsSummary())->toBe('Mon, Tue 06:00-10:00');
    expect(MediaFile::factory()->create(['airtime_windows' => null])->airtimeWindowsSummary())->toBeNull();
});

test('a run time includes its start and excludes its end', function () {
    $file = MediaFile::factory()->create([
        'airable_from' => '2026-12-01 00:00',
        'airable_until' => '2026-12-27 00:00',
    ]);

    expect($file->isAirableAt(Carbon\Carbon::parse('2026-11-30 23:59')))->toBeFalse();
    expect($file->isAirableAt(Carbon\Carbon::parse('2026-12-01 00:00')))->toBeTrue();
    expect($file->isAirableAt(Carbon\Carbon::parse('2026-12-26 23:59')))->toBeTrue();
    expect($file->isAirableAt(Carbon\Carbon::parse('2026-12-27 00:00')))->toBeFalse();
});

test('a run time with one open end only limits the other', function () {
    $expiring = MediaFile::factory()->create(['airable_until' => '2026-09-14 20:00']);
    $starting = MediaFile::factory()->create(['airable_from' => '2026-09-14 20:00']);

    expect($expiring->isAirableAt(Carbon\Carbon::parse('2020-01-01 12:00')))->toBeTrue();
    expect($expiring->isAirableAt(Carbon\Carbon::parse('2026-09-14 20:00')))->toBeFalse();
    expect($starting->isAirableAt(Carbon\Carbon::parse('2026-09-14 19:59')))->toBeFalse();
    expect($starting->isAirableAt(Carbon\Carbon::parse('2030-01-01 12:00')))->toBeTrue();
});

test('run time and windows must both allow the moment', function () {
    $file = MediaFile::factory()->create([
        'airable_from' => '2026-12-01 00:00',
        'airable_until' => '2026-12-27 00:00',
        'airtime_windows' => [['days' => [], 'from' => '06:00', 'to' => '10:00']],
    ]);

    expect($file->isAirableAt(Carbon\Carbon::parse('2026-12-10 07:00')))->toBeTrue();
    expect($file->isAirableAt(Carbon\Carbon::parse('2026-12-10 12:00')))->toBeFalse();
    expect($file->isAirableAt(Carbon\Carbon::parse('2026-11-10 07:00')))->toBeFalse();
});

test('the query scope honours the run time', function () {
    $tenantId = Station::factory()->create()->tenant_id;
    $open = MediaFile::factory()->create(['tenant_id' => $tenantId]);
    $december = MediaFile::factory()->create(['tenant_id' => $tenantId, 'airable_from' => '2026-12-01 00:00', 'airable_until' => '2027-01-01 00:00']);
    $expired = MediaFile::factory()->create(['tenant_id' => $tenantId, 'airable_until' => '2026-12-05 20:00']);

    $airable = fn (string $at) => MediaFile::where('tenant_id', $tenantId)->airableAt(Carbon\Carbon::parse($at))->pluck('id')->sort()->values()->all();

    expect($airable('2026-11-15 12:00'))->toBe([$open->id, $expired->id]);
    expect($airable('2026-12-10 12:00'))->toBe([$open->id, $december->id]);
});

test('a run time that has passed counts as expired', function () {
    $file = MediaFile::factory()->create(['airable_until' => '2026-09-14 20:00']);

    expect($file->hasExpired(Carbon\Carbon::parse('2026-09-14 19:59')))->toBeFalse();
    expect($file->hasExpired(Carbon\Carbon::parse('2026-09-14 20:00')))->toBeTrue();
    expect(MediaFile::factory()->create()->hasExpired())->toBeFalse();
});
