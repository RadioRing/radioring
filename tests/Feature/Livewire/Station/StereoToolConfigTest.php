<?php

use App\Livewire\Station\Edit;
use App\Models\Station;
use App\Models\StereoToolPreset;
use App\Models\User;
use App\Services\LiquidsoapCommandService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->actingAs($this->owner);

    Storage::fake('local');

    // Every stereo tool change asks the container to restart.
    $this->mock(LiquidsoapCommandService::class, function ($mock) {
        $mock->shouldReceive('restart')->andReturnTrue();
    });
});

test('the owner can set a license key once stereo tool is enabled', function () {
    $station = Station::factory()->create([
        'user_id' => $this->owner->id,
        'stereo_tool_enabled' => true,
    ]);

    Livewire::test(Edit::class, ['station' => $station])
        ->set('stereoToolLicenseKey', 'the-license')
        ->call('save')
        ->assertHasNoErrors();

    $station->refresh();
    expect($station->stereo_tool_license_key)->toBe('the-license');

    expect($station->stereo_tool_preset)->toBeNull();
    expect($station->stereoToolActive())->toBeTrue();
});

test('the owner can select an uploaded preset', function () {
    $station = Station::factory()->create([
        'user_id' => $this->owner->id,
        'stereo_tool_enabled' => true,
    ]);

    $preset = StereoToolPreset::factory()->withFile()->create(['station_id' => $station->id]);

    Livewire::test(Edit::class, ['station' => $station])
        ->set('stereoToolLicenseKey', 'the-license')
        ->set('stereoToolPreset', $preset->identifier())
        ->call('save')
        ->assertHasNoErrors();

    expect($station->fresh()->stereo_tool_preset)->toBe('upload:'.$preset->id);
});

test('an unknown preset identifier is rejected', function () {
    $station = Station::factory()->create([
        'user_id' => $this->owner->id,
        'stereo_tool_enabled' => true,
    ]);

    Livewire::test(Edit::class, ['station' => $station])
        ->set('stereoToolPreset', 'upload:999999')
        ->call('save')
        ->assertHasErrors('stereoToolPreset');
});

test('a preset belonging to another station cannot be selected', function () {
    $station = Station::factory()->create([
        'user_id' => $this->owner->id,
        'stereo_tool_enabled' => true,
    ]);

    $foreign = StereoToolPreset::factory()->withFile()->create();

    Livewire::test(Edit::class, ['station' => $station])
        ->set('stereoToolPreset', $foreign->identifier())
        ->call('save')
        ->assertHasErrors('stereoToolPreset');
});

test('the owner can upload a preset', function () {
    $station = Station::factory()->create([
        'user_id' => $this->owner->id,
        'stereo_tool_enabled' => true,
    ]);

    $file = UploadedFile::fake()->createWithContent(
        'modern.sts',
        "[Preset info]\nName=Modern Smooth\n[Pre Compressor]\nEnabled=1\n",
    );

    Livewire::test(Edit::class, ['station' => $station])
        ->set('presetUpload', $file)
        ->call('uploadPreset')
        ->assertHasNoErrors();

    $preset = $station->stereoToolPresets()->sole();

    // The name comes out of the file when the form leaves it empty.
    expect($preset->name)->toBe('Modern Smooth');
    expect($preset->uploaded_by)->toBe($this->owner->id);
    Storage::disk('local')->assertExists($preset->path);
});

test('a file that is not a preset is rejected', function () {
    $station = Station::factory()->create([
        'user_id' => $this->owner->id,
        'stereo_tool_enabled' => true,
    ]);

    $file = UploadedFile::fake()->createWithContent('song.mp3', "\0\0\0binary rubbish");

    Livewire::test(Edit::class, ['station' => $station])
        ->set('presetUpload', $file)
        ->call('uploadPreset')
        ->assertHasErrors('presetUpload');

    expect($station->stereoToolPresets()->count())->toBe(0);
});

test('deleting the active preset falls back to the factory settings', function () {
    $station = Station::factory()->create([
        'user_id' => $this->owner->id,
        'stereo_tool_enabled' => true,
        'stereo_tool_license_key' => 'the-license',
    ]);

    $preset = StereoToolPreset::factory()->withFile()->create(['station_id' => $station->id]);
    $station->update(['stereo_tool_preset' => $preset->identifier()]);

    Livewire::test(Edit::class, ['station' => $station])
        ->call('deletePreset', $preset->id)
        ->assertHasNoErrors();

    expect($station->fresh()->stereo_tool_preset)->toBeNull();
    expect($station->fresh()->stereoToolActive())->toBeTrue();
    Storage::disk('local')->assertMissing($preset->path);
});

test('a preset of another station cannot be deleted', function () {
    $station = Station::factory()->create([
        'user_id' => $this->owner->id,
        'stereo_tool_enabled' => true,
    ]);

    $foreign = StereoToolPreset::factory()->withFile()->create();

    // deletePreset scopes the lookup to this station, so a foreign id is simply not there.
    expect(fn () => Livewire::test(Edit::class, ['station' => $station])
        ->call('deletePreset', $foreign->id))
        ->toThrow(ModelNotFoundException::class);

    expect(StereoToolPreset::find($foreign->id))->not->toBeNull();
    Storage::disk('local')->assertExists($foreign->path);
});

test('stereo tool config is ignored when the station is not enabled', function () {
    $station = Station::factory()->create([
        'user_id' => $this->owner->id,
        'stereo_tool_enabled' => false,
    ]);

    Livewire::test(Edit::class, ['station' => $station])
        ->set('stereoToolLicenseKey', 'sneaky')
        ->call('save')
        ->assertHasNoErrors();

    expect($station->fresh()->stereo_tool_license_key)->toBeNull();
});

test('uploading is refused when the station is not enabled', function () {
    $station = Station::factory()->create([
        'user_id' => $this->owner->id,
        'stereo_tool_enabled' => false,
    ]);

    Livewire::test(Edit::class, ['station' => $station])
        ->set('presetUpload', UploadedFile::fake()->createWithContent('a.sts', "[Preset info]\nName=x\n"))
        ->call('uploadPreset')
        ->assertStatus(403);
});

test('the owner cannot enable stereo tool through the edit form', function () {
    $station = Station::factory()->create([
        'user_id' => $this->owner->id,
        'stereo_tool_enabled' => false,
    ]);

    // stereo_tool_enabled is not fillable and is never set by the Edit component:
    // enabling a station stays an admin decision.
    Livewire::test(Edit::class, ['station' => $station])
        ->set('name', 'Neuer Name')
        ->call('save')
        ->assertHasNoErrors();

    expect($station->fresh()->stereo_tool_enabled)->toBeFalse();
});
