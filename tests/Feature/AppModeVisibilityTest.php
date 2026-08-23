<?php

use App\Livewire\Admin\Users as AdminUsers;
use App\Livewire\Station\Create as StationCreate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('mode-dependent routes are registered in both modes', function (string $mode) {
    $mode === 'cloud' ? useCloudMode() : useStandaloneMode();

    // The mode is switchable at runtime while routes are cached at boot, so route
    // definitions must never depend on it. The guard lives in the controller instead.
    expect(Route::has('admin.impersonate'))->toBeTrue()
        ->and(Route::has('admin.impersonate.leave'))->toBeTrue()
        ->and(Route::has('dashboard'))->toBeTrue()
        ->and(Route::has('admin.users'))->toBeTrue()
        ->and(Route::has('admin.settings'))->toBeTrue();
})->with(['standalone', 'cloud']);

test('starting impersonation is refused in standalone mode', function () {
    useStandaloneMode();

    $admin = User::factory()->create(['is_admin' => true]);
    $target = User::factory()->create();

    $this->actingAs($admin)
        ->post(route('admin.impersonate', $target))
        ->assertForbidden();

    expect(session()->has('impersonator_id'))->toBeFalse();
});

test('banning a user is refused in standalone mode', function () {
    useStandaloneMode();

    $admin = User::factory()->create(['is_admin' => true]);
    $target = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(AdminUsers::class)
        ->call('toggleBan', $target->id)
        ->assertForbidden();

    expect($target->fresh()->banned_at)->toBeNull();
});

test('banning a user works in cloud mode', function () {
    useCloudMode();

    $admin = User::factory()->create(['is_admin' => true]);
    $target = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(AdminUsers::class)
        ->call('toggleBan', $target->id);

    expect($target->fresh()->banned_at)->not->toBeNull();
});

test('the admin user list hides ban and impersonate controls in standalone mode', function () {
    useStandaloneMode();

    $admin = User::factory()->create(['is_admin' => true]);
    User::factory()->create(['name' => 'Regular Person']);

    Livewire::actingAs($admin)
        ->test(AdminUsers::class)
        ->assertSee('Regular Person')
        ->assertDontSee('bi-lock')
        ->assertDontSee('bi-person-badge');
});

test('the admin user list shows ban and impersonate controls in cloud mode', function () {
    useCloudMode();

    $admin = User::factory()->create(['is_admin' => true]);
    User::factory()->create(['name' => 'Regular Person']);

    Livewire::actingAs($admin)
        ->test(AdminUsers::class)
        ->assertSee('bi-lock')
        ->assertSee('bi-person-badge');
});

test('the station create page hides the quota line in standalone mode', function () {
    useStandaloneMode();

    Livewire::actingAs(User::factory()->create())
        ->test(StationCreate::class)
        ->assertDontSee('Stationen genutzt');
});

test('the station create page shows the quota line in cloud mode', function () {
    useCloudMode();

    Livewire::actingAs(User::factory()->create())
        ->test(StationCreate::class)
        ->assertSee('Stationen genutzt');
});
