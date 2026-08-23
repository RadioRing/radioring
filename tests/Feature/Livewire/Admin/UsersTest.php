<?php

use App\Livewire\Admin\Users;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

test('an admin can ban and unban a user', function () {
    // Konto-Sperren gibt es nur im Cloud-Betrieb.
    config(['radioring.mode' => 'cloud']);

    $user = User::factory()->create();

    Livewire::test(Users::class)->call('toggleBan', $user->id);
    expect($user->fresh()->isBanned())->toBeTrue();

    Livewire::test(Users::class)->call('toggleBan', $user->id);
    expect($user->fresh()->isBanned())->toBeFalse();
});

test('an admin can grant and revoke admin rights', function () {
    $user = User::factory()->create();

    Livewire::test(Users::class)->call('toggleAdmin', $user->id);
    expect($user->fresh()->isAdmin())->toBeTrue();

    Livewire::test(Users::class)->call('toggleAdmin', $user->id);
    expect($user->fresh()->isAdmin())->toBeFalse();
});

test('an admin cannot ban themselves', function () {
    Livewire::test(Users::class)
        ->call('toggleBan', $this->admin->id)
        ->assertForbidden();

    expect($this->admin->fresh()->isBanned())->toBeFalse();
});

test('an admin cannot revoke their own admin rights here', function () {
    Livewire::test(Users::class)
        ->call('toggleAdmin', $this->admin->id)
        ->assertForbidden();

    expect($this->admin->fresh()->isAdmin())->toBeTrue();
});

test('the list can be searched by name or email', function () {
    User::factory()->create(['name' => 'Alice Example', 'email' => 'alice@example.com']);
    User::factory()->create(['name' => 'Bob Other', 'email' => 'bob@other.com']);

    Livewire::test(Users::class)
        ->set('search', 'alice')
        ->assertSee('Alice Example')
        ->assertDontSee('Bob Other');
});
