<?php

use App\Livewire\Station\Edit;
use App\Models\MediaFile;
use App\Models\Station;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Redis::spy();

    $this->owner = User::factory()->create();
    $this->station = Station::factory()->create(['user_id' => $this->owner->id]);
});

function libraryFile(Station $station, string $title, int $bytes = 8): MediaFile
{
    $file = MediaFile::factory()->create([
        'tenant_id' => $station->tenant_id,
        'title' => $title,
        'file_path' => "tenants/{$station->tenant_id}/media/".str($title)->slug().'.mp3',
    ]);

    Storage::disk('local')->put($file->file_path, str_repeat('a', $bytes));

    return $file;
}

test('an owner adds and removes emergency files', function () {
    $file = libraryFile($this->station, 'Technical fault');

    Livewire::actingAs($this->owner)
        ->test(Edit::class, ['station' => $this->station])
        ->call('addEmergencyFile', $file->id)
        ->assertHasNoErrors();

    expect($this->station->emergencyItems()->pluck('media_files.id'))->toContain($file->id);

    Livewire::actingAs($this->owner)
        ->test(Edit::class, ['station' => $this->station])
        ->call('removeEmergencyFile', $file->id);

    expect($this->station->emergencyItems()->count())->toBe(0);
});

test('added files keep the order they were added in', function () {
    $first = libraryFile($this->station, 'First');
    $second = libraryFile($this->station, 'Second');

    Livewire::actingAs($this->owner)
        ->test(Edit::class, ['station' => $this->station])
        ->call('addEmergencyFile', $second->id)
        ->call('addEmergencyFile', $first->id);

    expect($this->station->emergencyItems()->pluck('media_files.id')->all())
        ->toBe([$second->id, $first->id]);
});

test('the file count cap is enforced', function () {
    config()->set('radioring.emergency.max_files', 1);

    $component = Livewire::actingAs($this->owner)->test(Edit::class, ['station' => $this->station]);

    $component->call('addEmergencyFile', libraryFile($this->station, 'One')->id);
    $component->call('addEmergencyFile', libraryFile($this->station, 'Two')->id);

    expect($this->station->emergencyItems()->count())->toBe(1);
});

test('the size cap is enforced', function () {
    config()->set('radioring.emergency.max_bytes', 30);

    $component = Livewire::actingAs($this->owner)->test(Edit::class, ['station' => $this->station]);

    $component->call('addEmergencyFile', libraryFile($this->station, 'Small', 10)->id);
    $component->call('addEmergencyFile', libraryFile($this->station, 'Huge', 100)->id);

    expect($this->station->emergencyItems()->pluck('media_files.title')->all())->toBe(['Small']);
});

test('a file of another tenant cannot be added', function () {
    $foreign = libraryFile(Station::factory()->create(), 'Theirs');

    Livewire::actingAs($this->owner)
        ->test(Edit::class, ['station' => $this->station])
        ->call('addEmergencyFile', $foreign->id);
})->throws(ModelNotFoundException::class);

test('an editor cannot reach the station settings at all', function () {
    $editor = User::factory()->create();
    $this->station->members()->attach($editor->id, ['role' => 'editor']);

    Livewire::actingAs($editor)
        ->test(Edit::class, ['station' => $this->station])
        ->assertStatus(403);
});

test('the search only offers files that are not selected yet', function () {
    $selected = libraryFile($this->station, 'Selected jingle');
    libraryFile($this->station, 'Spare jingle');

    $this->station->emergencyItems()->attach($selected->id, ['position' => 0]);

    $candidates = Livewire::actingAs($this->owner)
        ->test(Edit::class, ['station' => $this->station])
        ->set('emergencySearch', 'jingle')
        ->viewData('emergencyCandidates');

    expect($candidates->pluck('title')->all())->toBe(['Spare jingle']);
});
