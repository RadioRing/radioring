<?php

use App\Livewire\Settings\Security;
use App\Models\User;
use Laravel\Fortify\Features;
use Livewire\Livewire;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
    Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]);
});

test('enabling 2FA produces a QR code svg', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $component = Livewire::test(Security::class)
        ->call('enable');

    $component->assertHasNoErrors();
    $component->assertSet('showModal', true);

    $svg = $component->get('qrCodeSvg');
    expect($svg)->toContain('<svg');
    expect($svg)->not->toContain('<?xml');
    expect($component->get('manualSetupKey'))->not->toBe('');

    // Modal wird serverseitig gerendert: SVG sichtbar, kein Spinner mehr.
    $html = $component->html();
    expect($html)->toContain('<svg');
    expect($html)->not->toContain('spinner-border');
});
