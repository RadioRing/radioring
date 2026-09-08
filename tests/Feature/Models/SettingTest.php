<?php

use App\Models\Setting;
use App\Services\Backup\BackupSettings;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Setting::flushMemo();
});

test('a setting is read from the database when the cache store is unreachable', function () {
    Setting::set('probe', 'stored value');
    Setting::flushMemo();

    // Redis refusing a connection throws out of the cache layer, not out of the query.
    Cache::shouldReceive('rememberForever')->andThrow(new RuntimeException('Connection refused'));

    expect(Setting::get('probe', 'fallback'))->toBe('stored value');
});

test('an unknown setting falls back to its default when the cache store is unreachable', function () {
    Cache::shouldReceive('rememberForever')->andThrow(new RuntimeException('Connection refused'));

    expect(Setting::get('never-set', 'fallback'))->toBe('fallback');
});

test('the backup schedule keeps its defaults when the cache store is unreachable', function () {
    // The scheduler reads these while the console kernel boots. Throwing here would take
    // down every Artisan command, key:generate on a fresh installation included.
    Cache::shouldReceive('rememberForever')->andThrow(new RuntimeException('Connection refused'));

    expect(BackupSettings::autoEnabled())->toBeFalse()
        ->and(BackupSettings::autoTime())->toBe(BackupSettings::DEFAULT_TIME)
        ->and(BackupSettings::retention())->toBe(BackupSettings::DEFAULT_RETENTION);
});

test('a write survives an unreachable cache store', function () {
    Cache::shouldReceive('forget')->andThrow(new RuntimeException('Connection refused'));

    Setting::set('probe', 'stored value');

    expect(Setting::query()->where('key', 'probe')->value('value'))->toBe('stored value');
});
