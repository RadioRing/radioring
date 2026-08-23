<?php

use App\Models\InviteCode;
use App\Models\User;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration is rejected without an invite code', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasErrors('invite_code');
    $this->assertGuest();
    expect(User::count())->toBe(0);
});

test('registration is rejected with an unknown invite code', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'invite_code' => 'NOPE-NOPE-NOPE',
    ]);

    $response->assertSessionHasErrors('invite_code');
    $this->assertGuest();
    expect(User::count())->toBe(0);
});

test('an invite code cannot be used twice', function () {
    $invite = InviteCode::factory()->used()->create();

    $response = $this->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'invite_code' => $invite->code,
    ]);

    $response->assertSessionHasErrors('invite_code');
    $this->assertGuest();
});

test('invite code matching is case insensitive and trims whitespace', function () {
    $invite = InviteCode::factory()->create(['code' => 'ABCD-EFGH-IJKL']);

    $response = $this->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'invite_code' => '  abcd-efgh-ijkl  ',
    ]);

    $response->assertSessionHasNoErrors();
    $this->assertAuthenticated();
    expect($invite->fresh()->used_at)->not->toBeNull();
});
