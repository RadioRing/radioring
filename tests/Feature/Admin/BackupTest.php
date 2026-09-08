<?php

use App\Jobs\CreateBackupJob;
use App\Livewire\Admin\Backups;
use App\Models\Backup;
use App\Models\User;
use App\Services\Backup\BackupService;
use App\Services\Backup\BackupSettings;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');

    $this->admin = User::factory()->admin()->create();
});

test('only admins reach the backup area', function () {
    $this->get(route('admin.backups'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create());
    $this->get(route('admin.backups'))->assertForbidden();

    $this->actingAs($this->admin);
    $this->get(route('admin.backups'))->assertOk();
});

test('starting a backup records it and hands it to the queue', function () {
    Queue::fake();

    Livewire::actingAs($this->admin)
        ->test(Backups::class)
        ->set('passphrase', 'a good passphrase')
        ->call('createBackup')
        ->assertHasNoErrors();

    $backup = Backup::sole();

    expect($backup->status)->toBe(Backup::STATUS_PENDING)
        ->and($backup->created_by)->toBe($this->admin->id);

    Queue::assertPushed(CreateBackupJob::class, fn ($job) => $job->backupId === $backup->id
        && $job->passphrase === 'a good passphrase');
});

test('a short passphrase is rejected', function () {
    Queue::fake();

    Livewire::actingAs($this->admin)
        ->test(Backups::class)
        ->set('passphrase', 'kurz')
        ->call('createBackup')
        ->assertHasErrors('passphrase');

    expect(Backup::count())->toBe(0);
    Queue::assertNothingPushed();
});

test('a second backup is not started while one is running', function () {
    Queue::fake();

    Backup::create(['status' => Backup::STATUS_RUNNING]);

    Livewire::actingAs($this->admin)
        ->test(Backups::class)
        ->call('createBackup');

    Queue::assertNothingPushed();
    expect(Backup::count())->toBe(1);
});

test('an admin downloads a finished archive', function () {
    $backup = Backup::create([
        'status' => Backup::STATUS_COMPLETED,
        'filename' => 'radioring-config-test.zip',
        'size_bytes' => 4,
    ]);

    Storage::disk('local')->put($backup->path(), 'data');

    $this->actingAs($this->admin)
        ->get(route('admin.backups.download', $backup))
        ->assertOk()
        ->assertDownload('radioring-config-test.zip');
});

test('a failed backup cannot be downloaded', function () {
    $backup = Backup::create(['status' => Backup::STATUS_FAILED, 'error' => 'disk full']);

    $this->actingAs($this->admin)
        ->get(route('admin.backups.download', $backup))
        ->assertNotFound();
});

test('non-admins cannot download an archive', function () {
    $backup = Backup::create([
        'status' => Backup::STATUS_COMPLETED,
        'filename' => 'radioring-config-test.zip',
    ]);

    Storage::disk('local')->put($backup->path(), 'data');

    $this->actingAs(User::factory()->create())
        ->get(route('admin.backups.download', $backup))
        ->assertForbidden();
});

test('deleting a backup removes its archive', function () {
    $backup = Backup::create([
        'status' => Backup::STATUS_COMPLETED,
        'filename' => 'radioring-config-test.zip',
    ]);

    Storage::disk('local')->put($backup->path(), 'data');

    Livewire::actingAs($this->admin)
        ->test(Backups::class)
        ->call('deleteBackup', $backup->id);

    Storage::disk('local')->assertMissing($backup->path());
    expect(Backup::count())->toBe(0);
});

test('the schedule settings are stored and applied right away', function () {
    $older = Backup::create(['status' => Backup::STATUS_COMPLETED, 'filename' => 'old.zip']);
    Storage::disk('local')->put($older->path(), 'data');
    Backup::create(['status' => Backup::STATUS_COMPLETED, 'filename' => 'new.zip']);

    Livewire::actingAs($this->admin)
        ->test(Backups::class)
        ->set('autoEnabled', true)
        ->set('autoTime', '04:30')
        ->set('retention', 1)
        ->set('autoPassphrase', 'nightly passphrase')
        ->call('saveSettings')
        ->assertHasNoErrors();

    expect(BackupSettings::autoEnabled())->toBeTrue()
        ->and(BackupSettings::autoTime())->toBe('04:30')
        ->and(BackupSettings::retention())->toBe(1)
        ->and(BackupSettings::passphrase())->toBe('nightly passphrase');

    // Lowering the retention takes effect immediately, not only on the next run.
    expect(Backup::count())->toBe(1);
    Storage::disk('local')->assertMissing($older->path());
});

test('the stored passphrase can be removed again', function () {
    BackupSettings::setPassphrase('nightly passphrase');

    Livewire::actingAs($this->admin)
        ->test(Backups::class)
        ->call('clearPassphrase');

    expect(BackupSettings::hasPassphrase())->toBeFalse();
});

test('the overview shows a finished backup with its size', function () {
    $backup = Backup::create([
        'kind' => BackupService::KIND_CONFIG,
        'status' => Backup::STATUS_COMPLETED,
        'filename' => 'radioring-config-test.zip',
        'size_bytes' => 2048,
    ]);

    Livewire::actingAs($this->admin)
        ->test(Backups::class)
        ->assertSee('radioring-config-test.zip')
        ->assertSee('2,0 KB')
        ->assertSee(route('admin.backups.download', $backup));
});
