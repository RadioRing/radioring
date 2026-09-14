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
