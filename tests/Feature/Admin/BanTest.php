<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a banned user cannot log in', function () {
    $user = User::factory()->banned()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('a non-banned user can still log in', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertSessionHasNoErrors();

    $this->assertAuthenticated();
});

test('a user banned mid-session is logged out on the next request', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $user->forceFill(['banned_at' => now()])->save();

    $this->get(route('dashboard'))->assertRedirect(route('login'));

    $this->assertGuest();
});

test('the ban does not apply while an admin is impersonating', function () {
    $admin = User::factory()->admin()->create();
    $banned = User::factory()->banned()->create();

    // Banned user wird normalerweise nicht impersonierbar (kein Admin), aber falls
    // er es schon ist (z. B. nachträglich gesperrt), darf die Session weiterlaufen.
    $this->actingAs($admin)->withSession(['impersonator_id' => $admin->id]);
    $this->actingAs($banned)->withSession(['impersonator_id' => $admin->id]);

    $this->get(route('station.create'))->assertOk();
    $this->assertAuthenticated();
});
