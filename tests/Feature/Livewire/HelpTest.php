<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $this->get(route('help.index'))->assertRedirect(route('login'));
});

test('authenticated users can view the help page without a station', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('help.index'))->assertOk();
});

test('the handbook follows the configured locale', function (string $locale, string $anchor) {
    app()->setLocale($locale);
    $this->actingAs(User::factory()->create());

    $this->get(route('help.index'))
        ->assertOk()
        ->assertSee('id="'.$anchor.'"', escape: false);
})->with([
    ['de', '1-grundbegriffe'],
    ['en', '1-core-concepts'],
]);

test('it falls back to the German handbook for an unknown locale', function () {
    // Deutsch ist die fuehrende Fassung, die englische kann nachhinken oder fehlen.
    app()->setLocale('fr');
    $this->actingAs(User::factory()->create());

    $this->get(route('help.index'))
        ->assertOk()
        ->assertSee('id="1-grundbegriffe"', escape: false);
});

test('both handbook files exist where the help page looks for them', function () {
    expect(is_file(base_path('docs/de/handbuch.md')))->toBeTrue()
        ->and(is_file(base_path('docs/en/handbook.md')))->toBeTrue();
});

test('the container image keeps the handbooks the help page reads at runtime', function () {
    // Die Hilfeseite liest docs/ zur Laufzeit. Schliesst der Build das Verzeichnis aus,
    // rendert die Seite im Portal nur noch "Handbuch nicht verfuegbar".
    $dockerignore = file_get_contents(base_path('.dockerignore'));
    $dockerfile = file_get_contents(base_path('Dockerfile'));

    expect($dockerignore)->toContain('!docs/de/handbuch.md')
        ->and($dockerignore)->toContain('!docs/en/handbook.md')
        ->and($dockerfile)->not->toMatch('/^\s+docs \\\\$/m');
});
