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

test('create makes a new user with a tenant', function () {
    $this->artisan('user:manage', [
        'email' => 'chef@example.com',
        '--create' => true,
        '--name' => 'Chef',
        '--password' => 'install-secret-pw',
    ])->assertSuccessful();

    $user = User::where('email', 'chef@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Chef')
        ->and($user->tenant_id)->not->toBeNull()
        ->and(Hash::check('install-secret-pw', $user->password))->toBeTrue();
});

test('create combined with admin and verify yields a usable operator account', function () {
    $this->artisan('user:manage', [
        'email' => 'admin@example.com',
        '--create' => true,
        '--admin' => true,
        '--verify' => true,
        '--password' => 'install-secret-pw',
    ])->assertSuccessful();

    $user = User::where('email', 'admin@example.com')->first();

    expect($user->is_admin)->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull();
});

test('create derives the name from the email when none is given', function () {
    $this->artisan('user:manage', [
        'email' => 'klaas@example.com',
        '--create' => true,
        '--password' => 'install-secret-pw',
    ])->assertSuccessful();

    expect(User::where('email', 'klaas@example.com')->first()->name)->toBe('klaas');
});

test('create generates a password when none is given', function () {
    $this->artisan('user:manage', [
        'email' => 'generated@example.com',
        '--create' => true,
    ])->assertSuccessful();

    expect(User::where('email', 'generated@example.com')->first()->password)->not->toBeEmpty();
});

test('create refuses an invalid email', function () {
    $this->artisan('user:manage', ['email' => 'not-an-email', '--create' => true])
        ->assertExitCode(1);

    expect(User::where('email', 'not-an-email')->exists())->toBeFalse();
});

test('create on an existing email edits that user instead of duplicating it', function () {
    $user = User::factory()->create();

    $this->artisan('user:manage', [
        'email' => $user->email,
        '--create' => true,
        '--admin' => true,
    ])->assertSuccessful();

    expect(User::where('email', $user->email)->count())->toBe(1)
        ->and($user->fresh()->is_admin)->toBeTrue();
});

test('without create an unknown email still fails', function () {
    $this->artisan('user:manage', ['email' => 'ghost@example.com', '--admin' => true])
        ->assertExitCode(1);

    expect(User::where('email', 'ghost@example.com')->exists())->toBeFalse();
});
