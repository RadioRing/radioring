<?php

use App\Enums\AppMode;
use App\Livewire\Admin\Settings;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Setting::flushMemo();
    $this->admin = User::factory()->create(['is_admin' => true]);
});

test('the page is admin only', function () {
    $regular = User::factory()->create();

    $this->actingAs($regular)->get(route('admin.settings'))->assertForbidden();
    $this->actingAs($this->admin)->get(route('admin.settings'))->assertOk();
});

test('it shows the mode that is currently active', function () {
    AppMode::switchTo(AppMode::Cloud);
    Setting::flushMemo();

    Livewire::actingAs($this->admin)
        ->test(Settings::class)
        ->assertSet('mode', 'cloud');
});

test('switching the mode takes effect immediately without a redeploy', function () {
    expect(AppMode::current())->toBe(AppMode::Standalone);

    Livewire::actingAs($this->admin)
        ->test(Settings::class)
        ->set('mode', 'cloud')
        ->call('save');

    Setting::flushMemo();

    expect(AppMode::current())->toBe(AppMode::Cloud)
        ->and(AppMode::isMultiTenant())->toBeTrue()
        ->and(Setting::get(AppMode::SETTING_KEY))->toBe('cloud');
});

test('the stored mode wins over the environment default', function () {
    config(['radioring.mode' => 'cloud']);
    AppMode::switchTo(AppMode::Standalone);
    Setting::flushMemo();

    expect(AppMode::current())->toBe(AppMode::Standalone);
});

test('the environment default applies while nothing is stored', function () {
    config(['radioring.mode' => 'cloud']);
    Setting::flushMemo();

    expect(AppMode::current())->toBe(AppMode::Cloud);
});

test('an invalid mode is rejected', function () {
    Livewire::actingAs($this->admin)
        ->test(Settings::class)
        ->set('mode', 'nonsense')
        ->call('save')
        ->assertHasErrors('mode');

    Setting::flushMemo();

    expect(AppMode::current())->toBe(AppMode::Standalone);
});

// ── Warnung beim Wechsel auf Standalone mit mehreren Mandanten ──────────────

test('it warns when switching to standalone would be ambiguous', function () {
    AppMode::switchTo(AppMode::Cloud);
    Setting::flushMemo();

    // The admin's own factory already created a tenant; it is the oldest and therefore
    // the one new registrations would join.
    $oldest = Tenant::query()->oldest('id')->first();
    Tenant::factory()->create(['name' => 'Radio Nord']);
    Tenant::factory()->create(['name' => 'Radio Sued']);

    Livewire::actingAs($this->admin)
        ->test(Settings::class)
        ->set('mode', 'standalone')
        ->assertSee('Existing media libraries stay separate')
        ->assertSee('There are 3 tenants on this instance.')
        ->assertSee($oldest->name);
});

test('it does not warn when only one tenant exists', function () {
    AppMode::switchTo(AppMode::Cloud);
    Setting::flushMemo();

    expect(Tenant::count())->toBe(1);

    Livewire::actingAs($this->admin)
        ->test(Settings::class)
        ->set('mode', 'standalone')
        ->assertDontSee('Existing media libraries stay separate');
});

test('it does not warn when switching from standalone to cloud', function () {
    Tenant::factory()->count(2)->create();

    Livewire::actingAs($this->admin)
        ->test(Settings::class)
        ->set('mode', 'cloud')
        ->assertDontSee('Existing media libraries stay separate');
});

// ── Wirkung des Schalters auf die abhaengigen Funktionen ────────────────────

test('impersonation is refused after switching to standalone', function () {
    AppMode::switchTo(AppMode::Cloud);
    Setting::flushMemo();

    $target = User::factory()->create();

    $this->actingAs($this->admin)
        ->post(route('admin.impersonate', $target))
        ->assertRedirect(route('dashboard'));

    auth()->logout();
    session()->flush();

    AppMode::switchTo(AppMode::Standalone);
    Setting::flushMemo();

    $this->actingAs($this->admin)
        ->post(route('admin.impersonate', $target))
        ->assertForbidden();
});
