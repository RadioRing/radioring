<?php

use App\Models\Station;
use App\Models\User;
use App\Services\UpdateChecker;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'radioring.version.repository' => 'radioring/radioring',
        'radioring.version.update_check' => true,
    ]);
    Http::preventStrayRequests();
});

function useBuild(string $version, ?string $commit = null): void
{
    config(['radioring.version.name' => $version, 'radioring.version.commit' => $commit]);
}

test('a release build reports a newer release with its notes', function () {
    useBuild('0.6.1', 'aaaaaaa1111');
    Http::fake([
        'api.github.com/repos/radioring/radioring/releases/latest' => Http::response([
            'tag_name' => 'v0.7.0',
            'name' => 'RadioRing 0.7.0',
            'html_url' => 'https://github.com/radioring/radioring/releases/tag/v0.7.0',
            'published_at' => '2026-10-01T10:00:00Z',
            'body' => "## Changes\n\n- New playlist generator",
        ]),
    ]);

    $update = UpdateChecker::forCurrentBuild()->refresh();

    expect($update['kind'])->toBe('release')
        ->and($update['label'])->toBe('v0.7.0')
        ->and($update['notes'])->toContain('New playlist generator')
        ->and(UpdateChecker::forCurrentBuild()->availableUpdate())->toBe($update);
});

test('a release build on the latest release has no update', function () {
    useBuild('0.7.0');
    Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v0.7.0', 'name' => '', 'html_url' => ''])]);

    expect(UpdateChecker::forCurrentBuild()->refresh())->toBeNull()
        ->and(UpdateChecker::forCurrentBuild()->availableUpdate())->toBeNull();
});

test('an edge build reports the commits it is missing from main, newest first', function () {
    useBuild('edge', 'abc1234def');
    Http::fake([
        'api.github.com/repos/radioring/radioring' => Http::response(['default_branch' => 'main']),
        'api.github.com/repos/radioring/radioring/compare/abc1234def...main' => Http::response([
            'ahead_by' => 2,
            'html_url' => 'https://github.com/radioring/radioring/compare/abc1234def...main',
            'commits' => [
                ['sha' => '1111111aaaa', 'commit' => ['message' => "Older fix\n\nDetails", 'committer' => ['date' => '2026-10-01T08:00:00Z']]],
                ['sha' => '2222222bbbb', 'commit' => ['message' => 'Newer feature', 'committer' => ['date' => '2026-10-02T08:00:00Z']]],
            ],
        ]),
    ]);

    $update = UpdateChecker::forCurrentBuild()->refresh();

    expect($update['kind'])->toBe('edge')
        ->and($update['behind'])->toBe(2)
        ->and($update['commits'][0])->toMatchArray(['sha' => '2222222', 'message' => 'Newer feature'])
        ->and($update['commits'][1]['message'])->toBe('Older fix');
});

test('an edge build at the head of main has no update', function () {
    useBuild('edge', 'abc1234def');
    Http::fake([
        'api.github.com/repos/radioring/radioring' => Http::response(['default_branch' => 'main']),
        'api.github.com/repos/radioring/radioring/compare/*' => Http::response(['ahead_by' => 0, 'commits' => []]),
    ]);

    expect(UpdateChecker::forCurrentBuild()->refresh())->toBeNull();
});

test('a failed lookup keeps the previously known update', function () {
    useBuild('0.6.1');
    Http::fakeSequence('api.github.com/*')
        ->push(['tag_name' => 'v0.7.0', 'name' => 'v0.7.0', 'html_url' => 'https://example.test', 'body' => ''])
        ->push([], 500);

    UpdateChecker::forCurrentBuild()->refresh();

    expect(UpdateChecker::forCurrentBuild()->refresh()['label'])->toBe('v0.7.0');
});

test('a new deployment does not inherit the answer of the previous build', function () {
    useBuild('0.6.1');
    Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v0.7.0', 'name' => 'v0.7.0', 'html_url' => 'https://example.test', 'body' => ''])]);
    UpdateChecker::forCurrentBuild()->refresh();

    useBuild('0.7.0');

    expect(UpdateChecker::forCurrentBuild()->availableUpdate())->toBeNull();
});

test('development builds and disabled checks never contact GitHub', function () {
    useBuild('');
    expect(UpdateChecker::forCurrentBuild()->refresh())->toBeNull();

    useBuild('0.6.1');
    config(['radioring.version.update_check' => false]);
    expect(UpdateChecker::forCurrentBuild()->refresh())->toBeNull();

    Http::assertNothingSent();
});

test('the check command stores the result', function () {
    useBuild('0.6.1');
    Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v0.7.0', 'name' => 'v0.7.0', 'html_url' => 'https://example.test', 'body' => ''])]);

    $this->artisan('radioring:check-updates')->assertSuccessful()->expectsOutputToContain('v0.7.0');

    expect(UpdateChecker::forCurrentBuild()->availableUpdate())->not->toBeNull();
});

test('admins see the highlighted badge with the release notes, other users do not', function () {
    useBuild('0.6.1');
    Http::fake(['api.github.com/*' => Http::response([
        'tag_name' => 'v0.7.0',
        'name' => 'RadioRing 0.7.0',
        'html_url' => 'https://github.com/radioring/radioring/releases/tag/v0.7.0',
        'body' => "- Shiny thing\n\n<script>alert(1)</script>",
    ])]);
    UpdateChecker::forCurrentBuild()->refresh();

    $admin = User::factory()->admin()->create();
    $admin->setCurrentStation(Station::factory()->create(['user_id' => $admin->id]));

    $this->actingAs($admin)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-test="update-badge"', false)
        ->assertSee('Shiny thing')
        ->assertDontSee('<script>alert(1)</script>', false);

    $user = User::factory()->create();
    $user->setCurrentStation(Station::factory()->create(['user_id' => $user->id]));

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('data-test="update-badge"', false)
        ->assertSee('v0.6.1');
});
