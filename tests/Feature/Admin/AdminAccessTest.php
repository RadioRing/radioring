<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests are redirected away from the admin area', function () {
    $this->get(route('admin.users'))->assertRedirect(route('login'));
});

test('non-admins are forbidden from the admin area', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('admin.users'))->assertForbidden();
    $this->get(route('admin.invite-codes'))->assertForbidden();
});

test('admins can access the admin area', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->get(route('admin.users'))->assertOk();
    $this->get(route('admin.invite-codes'))->assertOk();
});

test('the admin navigation link is rendered for admins', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->get(route('admin.users'))->assertSee(route('admin.invite-codes'));
});

test('non-admins do not see the admin navigation', function () {
    // Nutzer ohne Station landet auf station.create – die das App-Layout (Sidebar) rendert.
    $this->actingAs(User::factory()->create());

    $this->get(route('station.create'))->assertDontSee(route('admin.users'));
});
