<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('command fails for unknown email', function () {
    $this->artisan('user:manage', ['email' => 'nobody@example.com'])
        ->assertExitCode(1);
});

test('verify marks the email as verified', function () {
    $user = User::factory()->unverified()->create();

    $this->artisan('user:manage', ['email' => $user->email, '--verify' => true])
        ->assertSuccessful();

    expect($user->fresh()->email_verified_at)->not->toBeNull();
});

test('unverify clears the verification', function () {
    $user = User::factory()->create(); // verified by default

    $this->artisan('user:manage', ['email' => $user->email, '--unverify' => true])
        ->assertSuccessful();

    expect($user->fresh()->email_verified_at)->toBeNull();
});

test('quota and password can be set', function () {
    $user = User::factory()->create();

    $this->artisan('user:manage', [
        'email' => $user->email,
        '--quota' => '10',
        '--password' => 'new-secret-pw',
    ])->assertSuccessful();

    $user->refresh();
    expect($user->tenant->fresh()->station_quota)->toBe(10);
    expect(Hash::check('new-secret-pw', $user->password))->toBeTrue();
});
