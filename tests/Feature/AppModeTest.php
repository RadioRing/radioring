<?php

use App\Enums\AppMode;
use App\Models\Station;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the mode falls back to standalone for an unknown value', function () {
    config(['radioring.mode' => 'nonsense']);

    expect(AppMode::current())->toBe(AppMode::Standalone)
        ->and(AppMode::isMultiTenant())->toBeFalse();
});

test('the mode is read from config', function (string $value, AppMode $expected, bool $multiTenant) {
    config(['radioring.mode' => $value]);

    expect(AppMode::current())->toBe($expected)
        ->and(AppMode::isMultiTenant())->toBe($multiTenant);
})->with([
    ['standalone', AppMode::Standalone, false],
    ['cloud', AppMode::Cloud, true],
]);

// ── Mandanten-Zuordnung bei der Registrierung ───────────────────────────────

test('standalone registration joins the one existing tenant', function () {
    config(['radioring.mode' => 'standalone']);

    $first = User::factory()->create();
    $existingTenant = $first->tenant;

    $joined = Tenant::forStandalone();

    expect($joined->id)->toBe($existingTenant->id);
});

test('forStandalone creates the tenant when none exists yet', function () {
    config(['radioring.mode' => 'standalone', 'app.name' => 'My Radio']);

    expect(Tenant::count())->toBe(0);

    $tenant = Tenant::forStandalone();

    expect(Tenant::count())->toBe(1)
        ->and($tenant->name)->toBe('My Radio');
});

// ── Stations-Quota ─────────────────────────────────────────────────────────

test('the quota is not enforced in standalone mode', function () {
    config(['radioring.mode' => 'standalone']);

    $tenant = Tenant::factory()->create(['station_quota' => 1]);
    Station::factory()->count(3)->create(['tenant_id' => $tenant->id]);

    expect($tenant->canCreateStation())->toBeTrue();
});

test('the quota is enforced in cloud mode', function () {
    config(['radioring.mode' => 'cloud']);

    $tenant = Tenant::factory()->create(['station_quota' => 1]);

    expect($tenant->canCreateStation())->toBeTrue();

    Station::factory()->create(['tenant_id' => $tenant->id]);

    expect($tenant->fresh()->canCreateStation())->toBeFalse();
});
