<?php

use App\Models\Backup;
use App\Models\Station;
use App\Models\User;
use App\Services\Backup\BackupSettings;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

test('backup:run writes an archive and reports its size', function () {
    $this->artisan('backup:run')
        ->expectsOutputToContain('Backup written')
        ->assertSuccessful();

    $backup = Backup::sole();

    expect($backup->status)->toBe(Backup::STATUS_COMPLETED)
        ->and($backup->automatic)->toBeFalse();

    Storage::disk('local')->assertExists($backup->path());
});

test('backup:run --auto uses the stored passphrase and is marked automatic', function () {
    BackupSettings::setPassphrase('nightly passphrase');

    $this->artisan('backup:run --auto')->assertSuccessful();

    $backup = Backup::sole();

    expect($backup->automatic)->toBeTrue()
        ->and($backup->encrypted)->toBeTrue()
        ->and($backup->filename)->toEndWith('.enc');
});

test('backup:run applies the retention limit', function () {
    BackupSettings::setRetention(2);

    $this->artisan('backup:run')->assertSuccessful();
    $this->artisan('backup:run')->assertSuccessful();
    $this->artisan('backup:run')->assertSuccessful();

    expect(Backup::count())->toBe(2)
        ->and(Storage::disk('local')->files(Backup::DIRECTORY))->toHaveCount(2);
});

test('backup:restore brings the data of an archive back', function () {
    $user = User::factory()->create(['email' => 'operator@example.test']);
    Station::factory()->create(['user_id' => $user->id]);

    $this->artisan('backup:run')->assertSuccessful();
    $backup = Backup::sole();

    $user->delete();
    expect(User::where('email', 'operator@example.test')->exists())->toBeFalse();

    $this->artisan('backup:restore', ['backup' => (string) $backup->id, '--force' => true])
        ->expectsOutputToContain('Database restored.')
        ->assertSuccessful();

    expect(User::where('email', 'operator@example.test')->exists())->toBeTrue();
});

test('backup:restore opens an encrypted archive with its passphrase', function () {
    User::factory()->create(['email' => 'operator@example.test']);

    $this->artisan('backup:run --passphrase="a good passphrase"')->assertSuccessful();
    $backup = Backup::sole();

    User::where('email', 'operator@example.test')->delete();

    $this->artisan('backup:restore', [
        'backup' => (string) $backup->id,
        '--passphrase' => 'a good passphrase',
        '--force' => true,
    ])->assertSuccessful();

    expect(User::where('email', 'operator@example.test')->exists())->toBeTrue();
});

test('backup:restore refuses a wrong passphrase and changes nothing', function () {
    User::factory()->create(['email' => 'operator@example.test']);

    $this->artisan('backup:run --passphrase="a good passphrase"')->assertSuccessful();
    $backup = Backup::sole();

    $this->artisan('backup:restore', [
        'backup' => (string) $backup->id,
        '--passphrase' => 'the wrong one',
        '--force' => true,
    ])->assertFailed();

    expect(User::where('email', 'operator@example.test')->exists())->toBeTrue();
});

test('backup:restore fails on an unknown archive', function () {
    $this->artisan('backup:restore', ['backup' => 'does-not-exist.zip'])->assertFailed();
});
