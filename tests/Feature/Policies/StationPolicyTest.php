<?php

use App\Models\MediaFile;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->founder = User::factory()->create();
    $this->station = Station::factory()->create(['user_id' => $this->founder->id]);
});

/** Returns a user holding the given role ('founder', a StationRole value or 'stranger'). */
function userWithRole(Station $station, string $role): User
{
    if ($role === 'founder') {
        return $station->owner;
    }

    $user = User::factory()->create();

    if ($role !== 'stranger') {
        $station->members()->attach($user->id, ['role' => $role]);
    }

    return $user;
}

test('the station abilities follow the role matrix', function (string $ability, array $allowed) {
    foreach (['founder', 'owner', 'editor', 'presenter', 'stranger'] as $role) {
        expect(userWithRole($this->station, $role)->can($ability, $this->station))
            ->toBe(in_array($role, $allowed, true), "{$role} / {$ability}");
    }
})->with([
    'view' => ['view', ['founder', 'owner', 'editor', 'presenter']],
    'goLive' => ['goLive', ['founder', 'owner', 'editor', 'presenter']],
    'uploadMedia' => ['uploadMedia', ['founder', 'owner', 'editor', 'presenter']],
    'program' => ['program', ['founder', 'owner', 'editor']],
    'writeMedia' => ['writeMedia', ['founder', 'owner', 'editor']],
    'skip' => ['skip', ['founder', 'owner', 'editor']],
    'operate' => ['operate', ['founder', 'owner']],
    'deleteMedia' => ['deleteMedia', ['founder', 'owner']],
    'manage' => ['manage', ['founder', 'owner']],
    'delete' => ['delete', ['founder']],
]);

test('a presenter may edit their own uploads only', function () {
    $presenter = userWithRole($this->station, 'presenter');
    $own = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'uploaded_by' => $presenter->id]);
    $foreign = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id]);

    expect($presenter->can('update', [$own, $this->station]))->toBeTrue()
        ->and($presenter->can('update', [$foreign, $this->station]))->toBeFalse()
        ->and($this->founder->can('update', [$foreign, $this->station]))->toBeTrue();
});
