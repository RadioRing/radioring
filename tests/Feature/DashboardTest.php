<?php

use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $station = Station::factory()->create(['user_id' => $user->id]);
    $user->setCurrentStation($station);
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('an editor without own stations is taken to the shared station', function () {
    $station = Station::factory()->create();
    $editor = User::factory()->create();
    $station->members()->attach($editor->id, ['role' => 'editor']);
    $this->actingAs($editor);

    $this->get(route('dashboard'))->assertOk()->assertSee($station->name);
});

test('an admin who is owner of a foreign station is not sent to station creation', function () {
    $station = Station::factory()->create();
    $admin = User::factory()->admin()->create();
    $station->members()->attach($admin->id, ['role' => 'owner']);
    $this->actingAs($admin);

    $this->get(route('dashboard'))->assertOk()->assertSee($station->name);
});
