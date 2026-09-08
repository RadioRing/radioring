<?php

use App\Models\Backup;
use App\Models\Station;
use App\Models\User;
use App\Services\Backup\BackupCipher;
use App\Services\Backup\BackupService;
use App\Services\Backup\DatabaseDumper;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

/**
 * Runs a backup the way the job does, and returns the finished record.
 */
function runBackup(?string $passphrase = null): Backup
{
    $backup = Backup::create([
        'kind' => BackupService::KIND_CONFIG,
        'status' => Backup::STATUS_PENDING,
    ]);

    return app(BackupService::class)->run($backup, $passphrase);
}

test('a backup produces an archive with manifest and database dump', function () {
    $backup = runBackup();

    expect($backup->status)->toBe(Backup::STATUS_COMPLETED)
        ->and($backup->size_bytes)->toBeGreaterThan(0)
        ->and($backup->encrypted)->toBeFalse();

    Storage::disk('local')->assertExists($backup->path());

    $zip = new ZipArchive;
    expect($zip->open(Storage::disk('local')->path($backup->path())))->toBeTrue();

    $manifest = json_decode($zip->getFromName('manifest.json'), true);

    expect($manifest['app_key'])->toBe(config('app.key'))
        ->and($manifest['database_driver'])->toBe('sqlite')
        ->and($zip->getFromName('database.jsonl'))->toContain('"t":"meta"');

    $zip->close();
});

test('an encrypted archive opens only with its passphrase', function () {
    $backup = runBackup('correct horse battery');

    expect($backup->encrypted)->toBeTrue()
        ->and($backup->filename)->toEndWith('.enc');

    $archive = Storage::disk('local')->path($backup->path());
    $cipher = app(BackupCipher::class);

    expect($cipher->isEncrypted($archive))->toBeTrue();

    $target = Storage::disk('local')->path('decrypted.zip');

    expect(fn () => $cipher->decryptFile($archive, $target, 'wrong passphrase'))
        ->toThrow(RuntimeException::class);

    $cipher->decryptFile($archive, $target, 'correct horse battery');

    $zip = new ZipArchive;
    expect($zip->open($target))->toBeTrue()
        ->and($zip->getFromName('manifest.json'))->toContain('"kind": "config"');
    $zip->close();
});

test('a truncated encrypted archive is rejected instead of restored in part', function () {
    $backup = runBackup('correct horse battery');
    $archive = Storage::disk('local')->path($backup->path());

    file_put_contents($archive, substr(file_get_contents($archive), 0, 100));

    expect(fn () => app(BackupCipher::class)->decryptFile($archive, Storage::disk('local')->path('x.zip'), 'correct horse battery'))
        ->toThrow(RuntimeException::class);
});

test('retention deletes the oldest archives and their files', function () {
    $first = runBackup();
    $second = runBackup();

    app(BackupService::class)->prune(1);

    expect(Backup::whereKey($first->id)->exists())->toBeFalse()
        ->and(Backup::whereKey($second->id)->exists())->toBeTrue();

    Storage::disk('local')->assertMissing($first->path());
    Storage::disk('local')->assertExists($second->path());
});

test('failed runs stay in the list so a broken nightly backup stays visible', function () {
    $failed = Backup::create([
        'kind' => BackupService::KIND_CONFIG,
        'status' => Backup::STATUS_FAILED,
        'error' => 'disk full',
    ]);

    runBackup();
    app(BackupService::class)->prune(1);

    expect(Backup::whereKey($failed->id)->exists())->toBeTrue();
});

test('a dump restores the data it captured', function () {
    $user = User::factory()->create(['email' => 'operator@example.test']);
    $station = Station::factory()->create(['user_id' => $user->id]);

    $dump = Storage::disk('local')->path('dump.jsonl');
    $dumper = app(DatabaseDumper::class);
    $dumper->dump($dump);

    $station->delete();
    $user->delete();

    expect(User::where('email', 'operator@example.test')->exists())->toBeFalse();

    $dumper->restore($dump);

    expect(User::where('email', 'operator@example.test')->exists())->toBeTrue()
        ->and(Station::whereKey($station->id)->value('name'))->toBe($station->name);
});

test('a dump written by another database driver is refused', function () {
    $dump = Storage::disk('local')->path('foreign.jsonl');
    file_put_contents($dump, json_encode(['t' => 'meta', 'format' => 1, 'driver' => 'mysql'])."\n");

    expect(fn () => app(DatabaseDumper::class)->restore($dump))
        ->toThrow(RuntimeException::class, 'across database drivers');

    // The refusal has to come before anything is dropped.
    expect(User::query()->count())->toBe(0)
        ->and(Schema::hasTable('users'))->toBeTrue();
});

test('the backup history itself is not part of a dump', function () {
    runBackup();

    $dump = Storage::disk('local')->path('dump.jsonl');
    app(DatabaseDumper::class)->dump($dump);

    $rows = array_filter(
        array_map(fn ($line) => $line === '' ? null : json_decode($line, true), explode("\n", file_get_contents($dump))),
        fn ($record) => $record !== null && $record['t'] === 'rows' && $record['table'] === 'backups',
    );

    expect($rows)->toBeEmpty();
});
