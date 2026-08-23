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
