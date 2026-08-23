<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Impersonation gibt es nur im Cloud-Betrieb.
beforeEach(fn () => useCloudMode());

test('an admin can impersonate a regular user', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();

    $this->actingAs($admin)
        ->post(route('admin.impersonate', $target))
        ->assertRedirect(route('dashboard'));

    expect(auth()->id())->toBe($target->id);
    expect(session('impersonator_id'))->toBe($admin->id);
});

test('an admin cannot impersonate themselves', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.impersonate', $admin))
        ->assertForbidden();

    expect(session()->has('impersonator_id'))->toBeFalse();
});

test('an admin cannot impersonate another admin', function () {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.impersonate', $other))
        ->assertForbidden();
});

test('a regular user cannot start impersonation', function () {
    $user = User::factory()->create();
    $target = User::factory()->create();

    $this->actingAs($user)
        ->post(route('admin.impersonate', $target))
        ->assertForbidden();
});

test('leaving impersonation restores the admin', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();

    $this->actingAs($admin)->post(route('admin.impersonate', $target));

    $this->post(route('admin.impersonate.leave'))
        ->assertRedirect(route('admin.users'));

    expect(auth()->id())->toBe($admin->id);
    expect(session()->has('impersonator_id'))->toBeFalse();
});

test('impersonating clears the selected station from the session', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();

    session(['current_station_id' => 999]);

    $this->actingAs($admin)->post(route('admin.impersonate', $target));

    expect(session()->has('current_station_id'))->toBeFalse();
});
