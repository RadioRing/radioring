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

test('generator fades the programme out before a hard cut', function () {
    config()->set('radioring.hard_cut_fade_out_seconds', 0.8);

    $script = app(LiquidsoapScriptGenerator::class)->generate($this->station);

    // Adjustable programme volume - with an override key the /next API never emits,
    // otherwise amplify would apply the loudness correction (liq_amplify) a second time.
    expect($script)
        ->toContain('cut_gain = ref(1.)')
        ->toContain('amplify({cut_gain()}, override="liq_hard_cut_gain"')
        ->not->toContain('amplify({cut_gain()}, override="liq_amplify"')
        // ... ramping down before the cut and returning afterwards. The ramp is
        // logarithmic (constant dB per tick), so 0.8 s show up as 16 ticks of 0.05 s with
        // a factor of 10^(-60/320) and a floor at -60 dB.
        ->toContain('# Hard cut: fade out over 0.8000s (16 steps of 0.0500s), then cut.')
        ->toContain('def rec hard_cut_step()')
        ->toContain('cut_gain() * 0.6494')
        ->toContain('if gain <= 0.0010 then')
        ->toContain('thread.run(delay=0.0500, hard_cut_step)')
        ->toContain('cut_gain := 1.');

    // Der Container-Entrypoint ruft "radioring.flush_and_skip <sekunden>" per Telnet auf
    // und gibt den Vorlauf bis zum Schnitt mit. Die Rampe wird so gelegt, dass sie GENAU
    // dann endet: ein um 14:58 gestarteter Titel faded vor 15:00:00 aus statt danach.
    // Der erste Tick wird geplant, nicht inline ausgeführt - sonst käme nur 15 der 16
    // Ticks Zeit zusammen und der Cut läge bei 0,75 s statt 0,8 s.
    expect($script)
        ->toContain('def flush_and_skip(arg) =')
        ->toContain('lead = float_of_string(default=0., string.trim(arg))')
        ->toContain('wait = if lead > 0.8000 then lead - 0.8000 else 0. end')
        ->toContain('thread.run(delay=wait + 0.0500, hard_cut_step)')
        ->toContain('"flush_and_skip"');

    // The fallback takes the adjustable source, not the unadjusted one before it.
    expect($script)->toContain('fallback(track_sensitive=false, [live, program, blank()])');
});

test('the request.dynamic source does not sit in the telnet namespace of flush_and_skip', function () {
    // A source registers its own telnet commands under its id (skip, queue, set_queue, ...).
    // With id="radioring" they share the namespace our flush_and_skip is registered in, and
    // the server answered "radioring.flush_and_skip" from the built-in set: an instant cut,
    // no fade, no log line.
    $script = app(LiquidsoapScriptGenerator::class)->generate($this->station);

    expect($script)
        ->toContain('request.dynamic(id="program_queue"')
        ->not->toContain('id="radioring"')
        ->toContain('server.register(namespace="radioring"');
});

test('a fade-out of zero keeps the immediate cut but still honours the lead time', function () {
    config()->set('radioring.hard_cut_fade_out_seconds', 0.0);

    $script = app(LiquidsoapScriptGenerator::class)->generate($this->station);

    // Ohne Fade gibt es nichts auszublenden, der Schnitt muss aber trotzdem auf der
    // vollen Stunde liegen und nicht beim Eintreffen des Befehls.
    expect($script)
        ->toContain('def flush_and_skip(arg) =')
        ->toContain('lead = float_of_string(default=0., string.trim(arg))')
        ->toContain('thread.run(delay=lead, hard_cut_now)')
        ->not->toContain('hard_cut_step');
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
        ->toContain('[live, program, blank()]');
});

test('generator wires a per-element fade.in driven by the liq_fade_in annotation', function () {
    $script = app(LiquidsoapScriptGenerator::class)->generate($this->station);

    // duration=0. => standardmäßig harter Übergang; nur Elemente mit liq_fade_in-Annotation
    // (in der UI aktiviert) blenden ein. track_sensitive=true ist Pflicht, sonst blendet
    // fade.in (Default seit Liquidsoap 2.2: false) nur einmal beim Start der Source ein.
    expect($script)
        ->toContain('faded = fade.in(track_sensitive=true, override_duration="liq_fade_in", duration=0., normalized)')
        // The fade sits BEFORE the adjustable programme volume that feeds the fallback.
        ->toContain('program = amplify({cut_gain()}, override="liq_hard_cut_gain", faded)')
        ->toContain('[live, program, blank()]');
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

test('generator omits stereo tool when enabled but without a license key', function () {
    // Enabled but without a licence, which would leave Stereo Tool in demo mode with
    // dropouts, so it is left out entirely. stereo_tool_enabled is deliberately not
    // fillable (admin only), hence forceFill.
    $this->station->forceFill(['stereo_tool_enabled' => true])->save();

    $script = app(LiquidsoapScriptGenerator::class)->generate($this->station->fresh());

    expect($script)->not->toContain('stereotool(');
});

test('generator wires stereo tool without a preset argument when no preset is chosen', function () {
    config(['radioring.stereo_tool.library_file' => '/opt/stereotool/libStereoTool.so']);

    // A preset is optional: the Liquidsoap operator declares it nullable and leaves it
    // out when absent, so Stereo Tool runs with its factory settings.
    $this->station->forceFill([
        'stereo_tool_enabled' => true,
        'stereo_tool_license_key' => 'my-license-key',
        'stereo_tool_preset' => null,
    ])->save();

    $script = app(LiquidsoapScriptGenerator::class)->generate($this->station->fresh());

    expect($script)
        ->toContain('radio = stereotool(library_file="/opt/stereotool/libStereoTool.so", license_key="my-license-key", radio)')
        ->not->toContain('preset=');
});

test('generator wires stereo tool on the final radio source when fully configured', function () {
    config(['radioring.stereo_tool.library_file' => '/opt/stereotool/libStereoTool.so']);

    $this->station->forceFill([
        'stereo_tool_enabled' => true,
        'stereo_tool_license_key' => 'my-license-key',
    ])->save();

    $script = app(LiquidsoapScriptGenerator::class)->generate($this->station->fresh());

    // Processing runs ONCE on the finished radio source, live takeover included.
    // The preset argument has its own test, see StereoToolPresetDeliveryTest.
    expect($script)
        ->toContain('radio = stereotool(library_file="/opt/stereotool/libStereoTool.so", license_key="my-license-key", radio)');

    // Must sit AFTER the fallback and BEFORE the outputs.
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

test('generator installs the watchdog that wakes a stalled request queue', function () {
    $script = app(LiquidsoapScriptGenerator::class)->generate($this->station);

    // Vorfall vom 09.09.2026: nach einem leer gelaufenen Rundown blieb request.dynamic
    // stehen und die Station sendete 21 Minuten Stille, bis jemand skip gedrueckt hat.
    expect($script)
        ->toContain('def rec request_queue_watchdog()')
        ->toContain('.is_ready()')
        ->toContain('source.set_queue([])')
        ->toContain('thread.run(delay=10.0000, request_queue_watchdog)');
});
