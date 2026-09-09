<?php

use App\Models\Station;
use App\Models\StereoToolPreset;
use App\Services\LiquidsoapScriptGenerator;
use App\Support\StereoToolPresetLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');

    $this->station = Station::factory()->create([
        'stereo_tool_enabled' => true,
        'stereo_tool_license_key' => 'the-license',
    ]);
});

function presetRequest(Station $station): TestResponse
{
    return test()->withHeader('Authorization', 'Bearer '.$station->api_token)
        ->get("/api/liquidsoap/{$station->slug}/stereo-tool/preset");
}

test('the endpoint serves an uploaded preset to the station container', function () {
    $preset = StereoToolPreset::factory()
        ->withFile("[Preset info]\nName=Loudness only\n")
        ->create(['station_id' => $this->station->id]);

    $this->station->update(['stereo_tool_preset' => $preset->identifier()]);

    $response = presetRequest($this->station)->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('text/plain');
    expect(file_get_contents($response->baseResponse->getFile()->getPathname()))
        ->toContain('Name=Loudness only');
});

test('the endpoint answers 404 when no preset is selected', function () {
    presetRequest($this->station)->assertNotFound();
});

test('the endpoint answers 404 when the selected preset no longer exists', function () {
    $preset = StereoToolPreset::factory()->withFile()->create(['station_id' => $this->station->id]);
    $this->station->update(['stereo_tool_preset' => $preset->identifier()]);

    $preset->deleteWithFile();

    presetRequest($this->station->fresh())->assertNotFound();
});

test('the endpoint answers 404 while stereo tool is not active', function () {
    $preset = StereoToolPreset::factory()->withFile()->create(['station_id' => $this->station->id]);

    $this->station->update(['stereo_tool_preset' => $preset->identifier()]);
    $this->station->forceFill(['stereo_tool_enabled' => false])->save();

    presetRequest($this->station->fresh())->assertNotFound();
});

test('the endpoint rejects a wrong token', function () {
    $this->withHeader('Authorization', 'Bearer nonsense')
        ->get("/api/liquidsoap/{$this->station->slug}/stereo-tool/preset")
        ->assertUnauthorized();
});

test('a station cannot fetch a preset belonging to another station', function () {
    $foreign = StereoToolPreset::factory()->withFile()->create();

    // Not a made up identifier: it points at a real row, just not at one of ours.
    $this->station->update(['stereo_tool_preset' => $foreign->identifier()]);

    presetRequest($this->station->fresh())->assertNotFound();
});

test('the generated script points at the fetched preset file only when one resolves', function () {
    config([
        'radioring.stereo_tool.library_file' => '/opt/stereotool/libStereoTool.so',
        'radioring.stereo_tool.active_preset_file' => '/app/liquidsoap/stereotool-preset.sts',
    ]);

    $generator = app(LiquidsoapScriptGenerator::class);

    expect($generator->generate($this->station))->not->toContain('preset=');

    $preset = StereoToolPreset::factory()->withFile()->create(['station_id' => $this->station->id]);
    $this->station->update(['stereo_tool_preset' => $preset->identifier()]);

    expect($generator->generate($this->station->fresh()))
        ->toContain('preset="/app/liquidsoap/stereotool-preset.sts"');
});

test('a preset shipped with radioring resolves to its file in the repository', function () {
    $directory = StereoToolPresetLibrary::bundledPath();
    $file = $directory.'/pest-fixture.sts';

    File::ensureDirectoryExists($directory);
    File::put($file, "[Preset info]\nName=Pest Fixture\n");

    try {
        expect(StereoToolPresetLibrary::bundled())->toHaveKey('bundled:pest-fixture');
        expect(StereoToolPresetLibrary::bundled()['bundled:pest-fixture'])->toBe('Pest Fixture');

        $this->station->update(['stereo_tool_preset' => 'bundled:pest-fixture']);

        expect(StereoToolPresetLibrary::resolve($this->station->fresh()))->toBe($file);

        $response = presetRequest($this->station->fresh())->assertOk();

        expect(file_get_contents($response->baseResponse->getFile()->getPathname()))
            ->toContain('Name=Pest Fixture');
    } finally {
        File::delete($file);
    }
});

test('a bundled identifier cannot escape the preset directory', function () {
    $this->station->update(['stereo_tool_preset' => 'bundled:../../../../etc/passwd']);

    expect(StereoToolPresetLibrary::resolve($this->station->fresh()))->toBeNull();

    presetRequest($this->station->fresh())->assertNotFound();
});

test('preset content is checked loosely enough to accept ini and reject binaries', function () {
    expect(StereoToolPresetLibrary::looksLikePreset("[Preset info]\nName=x\n"))->toBeTrue();
    expect(StereoToolPresetLibrary::looksLikePreset("[Pre Compressor]\r\nEnabled=1\r\n"))->toBeTrue();

    expect(StereoToolPresetLibrary::looksLikePreset(''))->toBeFalse();
    expect(StereoToolPresetLibrary::looksLikePreset("ID3\x03\x00\x00binary"))->toBeFalse();
    expect(StereoToolPresetLibrary::looksLikePreset('just a sentence, no sections'))->toBeFalse();
    expect(StereoToolPresetLibrary::looksLikePreset('[Only a section]'))->toBeFalse();
});

test('deleting a station removes its preset rows and their files', function () {
    $preset = StereoToolPreset::factory()->withFile()->create(['station_id' => $this->station->id]);
    $other = StereoToolPreset::factory()->withFile()->create();

    Storage::disk('local')->assertExists($preset->path);

    $this->station->delete();

    expect(StereoToolPreset::find($preset->id))->toBeNull();
    Storage::disk('local')->assertMissing($preset->path);

    expect(StereoToolPreset::find($other->id))->not->toBeNull();
    Storage::disk('local')->assertExists($other->path);
});

test('deleting a station without presets leaves no trace behind', function () {
    $directory = $this->station->stereoToolPresetDirectory();

    $this->station->delete();

    expect(Storage::disk('local')->exists($directory))->toBeFalse();
});
