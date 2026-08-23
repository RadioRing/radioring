<?php

use App\Enums\AppMode;

test('lang/de.json is valid JSON with string values only', function () {
    $path = lang_path('de.json');

    expect(file_exists($path))->toBeTrue();

    $translations = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    expect($translations)->toBeArray()->not->toBeEmpty();

    foreach ($translations as $key => $value) {
        expect($key)->toBeString()->not->toBe('');
        expect($value)->toBeString()->not->toBe('');
    }
});

test('placeholders survive translation', function () {
    $translations = json_decode(file_get_contents(lang_path('de.json')), true);

    foreach ($translations as $key => $value) {
        preg_match_all('/:([a-zA-Z_]+)/', $key, $inKey);
        preg_match_all('/:([a-zA-Z_]+)/', $value, $inValue);

        sort($inKey[1]);
        sort($inValue[1]);

        expect($inValue[1])->toBe($inKey[1], "Platzhalter weichen ab bei: {$key}");
    }
});

test('the German locale actually translates', function () {
    app()->setLocale('en');
    $english = AppMode::Standalone->label();

    app()->setLocale('de');
    $german = AppMode::Standalone->label();

    expect($english)->toBe('Standalone (one tenant)')
        ->and($german)->toBe('Standalone (ein Mandant)')
        ->and($german)->not->toBe($english);
});
