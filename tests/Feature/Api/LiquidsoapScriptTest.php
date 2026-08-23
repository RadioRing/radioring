<?php

use App\Models\Station;
use App\Models\StationOutput;
use App\Models\StationStream;
use App\Models\User;
use App\Services\LiquidsoapScriptGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->station = Station::factory()->create(['user_id' => $this->user->id]);
    $this->token = $this->station->api_token;
});

// ── /api/liquidsoap/{slug}/script ───────────────────────────────────────────

test('script rejects missing token', function () {
    $this->get("/api/liquidsoap/{$this->station->slug}/script")
        ->assertStatus(401);
});

test('script rejects wrong token', function () {
    $this->withToken('wrong-token')
        ->get("/api/liquidsoap/{$this->station->slug}/script")
        ->assertStatus(401);
});

test('script endpoint returns generated liq with token auth', function () {
    $response = $this->withToken($this->token)
        ->get("/api/liquidsoap/{$this->station->slug}/script");

    $response->assertStatus(200);
    expect($response->getContent())
        ->toContain('request.dynamic')
        ->toContain($this->station->slug)
        ->toContain($this->token);
});

// ── LiquidsoapScriptGenerator ───────────────────────────────────────────────

test('generator includes pull, now-playing and harbor blocks', function () {
    $script = app(LiquidsoapScriptGenerator::class)->generate($this->station);

    expect($script)
        ->toContain('def next_track()')
        ->toContain('slug = "'.$this->station->slug.'"')
        ->toContain('/api/liquidsoap/#{slug}/next')
        ->toContain('radio.on_metadata')
        ->toContain('/api/liquidsoap/#{slug}/now-playing')
        ->toContain('input.harbor');
});

test('generator registers the flush_and_skip telnet command for hard cuts', function () {
    $script = app(LiquidsoapScriptGenerator::class)->generate($this->station);

    // Der Container-Entrypoint ruft "radioring.flush_and_skip" per Telnet auf –
    // ohne diese Registrierung wäre der Hard-Cut ein No-Op.
    expect($script)
        ->toContain('def flush_and_skip(')
        ->toContain('source.set_queue([])')
        ->toContain('source.skip()')
        ->toContain('"flush_and_skip"');
});

test('generator wraps every http call in try/catch so a curl error cannot crash the engine', function () {
    // Ein einzelner Verbindungsabbruch (z. B. CURLE_RECV_ERROR) warf früher einen
    // "uncaught" Runtime-Error und killte die ganze Liquidsoap-Engine. Alle drei
    // http-Aufrufe (next_track/now-playing/live) müssen abgesichert sein.
    $script = app(LiquidsoapScriptGenerator::class)->generate($this->station);

    expect($script)
        ->toContain('try')
        ->toContain('catch err do')
        // next_track liefert bei Fehler null() statt zu crashen
        ->toContain('body = ref("")')
        ->toContain('request.create(body())');

    // Jeder der drei http-Aufrufe steht in einem try-Block.
    expect(substr_count($script, 'try'))->toBeGreaterThanOrEqual(3);
});

test('generator registers a crash-safe "safe:" request protocol', function () {
    // Der eigentliche Crash (CURLE_RECV_ERROR) kam NICHT vom /next-Abruf, sondern
    // vom nachgelagerten Download der Track-URL durch Liquidsoaps eingebauten
    // http-Resolver – dort uncaught und damit engine-killend. Die eingebauten
    // Protokolle lassen sich nicht überschreiben, daher liefert /next die URLs mit
    // "safe:"-Prefix, das dieses try/catch-gekapselte Protokoll auflöst.
    $script = app(LiquidsoapScriptGenerator::class)->generate($this->station);

    expect($script)
        ->toContain('def protocol_safe(~rlog, ~maxtime, arg)')
        ->toContain('response = http.get(arg)')
        ->toContain('protocol.add(temporary=true, "safe", protocol_safe)');

    // Der Guard muss VOR der request.dynamic-Quelle stehen, damit die
    // Protokoll-Registrierung beim Auflösen bereits greift.
    expect(strpos($script, 'protocol_safe'))
        ->toBeLessThan(strpos($script, 'request.dynamic'));
});

test('generator emits an icecast output per enabled output', function () {
    StationOutput::create([
        'station_id' => $this->station->id,
        'type' => 'icecast',
        'host' => 'icecast',
        'port' => 8000,
        'mount' => '/myslug',
        'password' => 'hackme',
        'bitrate' => 192,
        'enabled' => true,
    ]);

    StationOutput::create([
        'station_id' => $this->station->id,
        'type' => 'icecast',
        'host' => 'disabled-host',
        'port' => 8000,
        'mount' => '/off',
        'password' => 'x',
        'enabled' => false,
    ]);

    $script = app(LiquidsoapScriptGenerator::class)->generate($this->station);

    expect($script)
        ->toContain('output.icecast')
        ->toContain('host="icecast"')
        ->toContain('mount="/myslug"')
        ->toContain('%mp3(bitrate=192)')
        ->not->toContain('disabled-host');
});

test('generator adds loudness normalization by default', function () {
    $script = app(LiquidsoapScriptGenerator::class)->generate($this->station);

    // Lautheit wird offline gemessen und per /next annotiert – KEINE Live-Autocue-Messung
    // mehr (die crashte bei defekten MP3s den ganzen Prozess).
    expect($script)
        ->not->toContain('enable_autocue_metadata')
        ->toContain('normalized = amplify(1., override="liq_amplify", source)')
        ->toContain('[live, faded, blank()]');
});

test('generator wires a per-element fade.in driven by the liq_fade_in annotation', function () {
    $script = app(LiquidsoapScriptGenerator::class)->generate($this->station);

    // duration=0. => standardmäßig harter Übergang; nur Elemente mit liq_fade_in-Annotation
    // (in der UI aktiviert) blenden ein. track_sensitive=true ist Pflicht, sonst blendet
    // fade.in (Default seit Liquidsoap 2.2: false) nur einmal beim Start der Source ein.
    expect($script)
        ->toContain('faded = fade.in(track_sensitive=true, override_duration="liq_fade_in", duration=0., normalized)')
        ->toContain('[live, faded, blank()]');
});

test('loudness normalization does not overwrite the request.dynamic source', function () {
    // amplify() liefert eine Source ohne set_queue/skip – flush_and_skip muss weiter
    // auf der unveränderten request.dynamic-Quelle "source" arbeiten.
    $script = app(LiquidsoapScriptGenerator::class)->generate($this->station);

    expect($script)
        ->toContain('source = request.dynamic(')
        ->toContain('source.set_queue([])')
        ->toContain('source.skip()')
        ->not->toContain('source = amplify');
});

test('generator omits loudness normalization when disabled', function () {
    config(['radioring.loudness.enabled' => false]);

    $script = app(LiquidsoapScriptGenerator::class)->generate($this->station);

    expect($script)
        ->not->toContain('enable_autocue_metadata')
        ->not->toContain('liq_amplify');
});

test('generator wires harbor connect/disconnect callbacks to report live status', function () {
    $script = app(LiquidsoapScriptGenerator::class)->generate($this->station);

    expect($script)
        ->toContain('on_connect=on_live_connect')
        ->toContain('on_disconnect=on_live_disconnect')
        ->toContain('def on_live_connect(_)')
        ->toContain('def on_live_disconnect()')
        ->toContain('/api/liquidsoap/#{slug}/live');
});

test('generator never emits an autocue target (offline measurement)', function () {
    config(['radioring.loudness.enabled' => true, 'radioring.loudness.target_lufs' => -16.0]);

    $script = app(LiquidsoapScriptGenerator::class)->generate($this->station);

    expect($script)->not->toContain('settings.autocue.target');
});

test('generator uses configured liquidsoap api url over app url', function () {
    config(['radioring.liquidsoap_api_url' => 'http://host.docker.internal:8000']);

    $script = app(LiquidsoapScriptGenerator::class)->generate($this->station);

    expect($script)->toContain('api_url = "http://host.docker.internal:8000"');
});

test('generator uses stream live port and password when present', function () {
    StationStream::create([
        'station_id' => $this->station->id,
        'container_name' => 'radioring-'.$this->station->slug,
        'status' => 'stopped',
        'live_port' => 9999,
        'live_password' => 'secretpw',
    ]);

    $this->station->refresh();
    $script = app(LiquidsoapScriptGenerator::class)->generate($this->station);

    expect($script)
        ->toContain('port=9999')
        ->toContain('password="secretpw"');
});

test('generator omits stereo tool when the station is not enabled', function () {
    $script = app(LiquidsoapScriptGenerator::class)->generate($this->station);

    expect($script)->not->toContain('stereotool(');
});

test('generator omits stereo tool when enabled but not fully configured', function () {
    // Freigeschaltet, aber ohne Lizenz/Preset – dann liefe Stereo Tool nur im
    // Demo-Modus mit Aussetzern, also lieber ganz weglassen. stereo_tool_enabled
    // ist bewusst nicht fillable (Admin-only), daher forceFill.
    $this->station->forceFill(['stereo_tool_enabled' => true])->save();

    $script = app(LiquidsoapScriptGenerator::class)->generate($this->station->fresh());

    expect($script)->not->toContain('stereotool(');
});

test('generator wires stereo tool on the final radio source when fully configured', function () {
    config([
        'radioring.stereo_tool.library_file' => '/opt/stereotool/libStereoTool.so',
        'radioring.stereo_tool.presets_path' => '/opt/stereotool/presets',
    ]);

    $this->station->forceFill([
        'stereo_tool_enabled' => true,
        'stereo_tool_license_key' => 'my-license-key',
        'stereo_tool_preset' => 'pop',
    ])->save();

    $script = app(LiquidsoapScriptGenerator::class)->generate($this->station->fresh());

    // Processing läuft EINMAL auf die fertige radio-Source (inkl. Live-Übernahme).
    expect($script)
        ->toContain('radio = stereotool(library_file="/opt/stereotool/libStereoTool.so", license_key="my-license-key", preset="/opt/stereotool/presets/pop.sts", radio)');

    // Muss NACH dem fallback und VOR den Outputs stehen.
    expect(strpos($script, 'radio = fallback'))
        ->toBeLessThan(strpos($script, 'radio = stereotool'));
    expect(strpos($script, 'radio = stereotool'))
        ->toBeLessThan(strpos($script, 'output.icecast') ?: PHP_INT_MAX);
});

test('station stereo tool license key is encrypted at rest', function () {
    $this->station->update(['stereo_tool_license_key' => 'secret-license']);

    expect($this->station->getRawOriginal('stereo_tool_license_key'))->not->toBe('secret-license');
    expect($this->station->fresh()->stereo_tool_license_key)->toBe('secret-license');
});

test('station output password is encrypted at rest', function () {
    $output = StationOutput::create([
        'station_id' => $this->station->id,
        'type' => 'icecast',
        'host' => 'icecast',
        'port' => 8000,
        'mount' => '/myslug',
        'password' => 'hackme',
    ]);

    expect($output->getRawOriginal('password_enc'))->not->toBe('hackme');
    expect($output->fresh()->password)->toBe('hackme');
});
