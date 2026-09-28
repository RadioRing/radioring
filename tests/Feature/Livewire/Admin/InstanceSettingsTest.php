<?php

use App\Enums\AppMode;
use App\Livewire\Admin\Settings;
use App\Mail\TestMail;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Mail\MailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Setting::flushMemo();
    $this->admin = User::factory()->create(['is_admin' => true]);
});

test('the page is admin only', function () {
    $regular = User::factory()->create();

    $this->actingAs($regular)->get(route('admin.settings'))->assertForbidden();
    $this->actingAs($this->admin)->get(route('admin.settings'))->assertOk();
});

test('it shows the mode that is currently active', function () {
    AppMode::switchTo(AppMode::Cloud);
    Setting::flushMemo();

    Livewire::actingAs($this->admin)
        ->test(Settings::class)
        ->assertSet('mode', 'cloud');
});

test('switching the mode takes effect immediately without a redeploy', function () {
    expect(AppMode::current())->toBe(AppMode::Standalone);

    Livewire::actingAs($this->admin)
        ->test(Settings::class)
        ->set('mode', 'cloud')
        ->call('save');

    Setting::flushMemo();

    expect(AppMode::current())->toBe(AppMode::Cloud)
        ->and(AppMode::isMultiTenant())->toBeTrue()
        ->and(Setting::get(AppMode::SETTING_KEY))->toBe('cloud');
});

test('the stored mode wins over the environment default', function () {
    config(['radioring.mode' => 'cloud']);
    AppMode::switchTo(AppMode::Standalone);
    Setting::flushMemo();

    expect(AppMode::current())->toBe(AppMode::Standalone);
});

test('the environment default applies while nothing is stored', function () {
    config(['radioring.mode' => 'cloud']);
    Setting::flushMemo();

    expect(AppMode::current())->toBe(AppMode::Cloud);
});

test('an invalid mode is rejected', function () {
    Livewire::actingAs($this->admin)
        ->test(Settings::class)
        ->set('mode', 'nonsense')
        ->call('save')
        ->assertHasErrors('mode');

    Setting::flushMemo();

    expect(AppMode::current())->toBe(AppMode::Standalone);
});

// ── Warnung beim Wechsel auf Standalone mit mehreren Mandanten ──────────────

test('it warns when switching to standalone would be ambiguous', function () {
    AppMode::switchTo(AppMode::Cloud);
    Setting::flushMemo();

    // The admin's own factory already created a tenant; it is the oldest and therefore
    // the one new registrations would join.
    $oldest = Tenant::query()->oldest('id')->first();
    Tenant::factory()->create(['name' => 'Radio Nord']);
    Tenant::factory()->create(['name' => 'Radio Sued']);

    Livewire::actingAs($this->admin)
        ->test(Settings::class)
        ->set('mode', 'standalone')
        ->assertSee('Existing media libraries stay separate')
        ->assertSee('There are 3 tenants on this instance.')
        ->assertSee($oldest->name);
});

test('it does not warn when only one tenant exists', function () {
    AppMode::switchTo(AppMode::Cloud);
    Setting::flushMemo();

    expect(Tenant::count())->toBe(1);

    Livewire::actingAs($this->admin)
        ->test(Settings::class)
        ->set('mode', 'standalone')
        ->assertDontSee('Existing media libraries stay separate');
});

test('it does not warn when switching from standalone to cloud', function () {
    Tenant::factory()->count(2)->create();

    Livewire::actingAs($this->admin)
        ->test(Settings::class)
        ->set('mode', 'cloud')
        ->assertDontSee('Existing media libraries stay separate');
});

// ── Wirkung des Schalters auf die abhaengigen Funktionen ────────────────────

test('impersonation is refused after switching to standalone', function () {
    AppMode::switchTo(AppMode::Cloud);
    Setting::flushMemo();

    $target = User::factory()->create();

    $this->actingAs($this->admin)
        ->post(route('admin.impersonate', $target))
        ->assertRedirect(route('dashboard'));

    auth()->logout();
    session()->flush();

    AppMode::switchTo(AppMode::Standalone);
    Setting::flushMemo();

    $this->actingAs($this->admin)
        ->post(route('admin.impersonate', $target))
        ->assertForbidden();
});

// ── Outgoing mail ───────────────────────────────────────────────────────────

test('mail settings are stored with the password encrypted', function () {
    Livewire::actingAs($this->admin)
        ->test(Settings::class)
        ->set('mailEnabled', true)
        ->set('mailHost', 'smtp.example.com')
        ->set('mailPort', 465)
        ->set('mailEncryption', 'tls')
        ->set('mailUsername', 'radio')
        ->set('mailPassword', 'secret-password')
        ->set('mailFromAddress', 'radio@example.com')
        ->call('saveMail')
        ->assertHasNoErrors()
        ->assertSet('mailPassword', '');

    Setting::flushMemo();

    expect(MailSettings::values())->toMatchArray([
        'enabled' => true,
        'host' => 'smtp.example.com',
        'port' => 465,
        'encryption' => 'tls',
        'from_address' => 'radio@example.com',
    ])
        ->and(MailSettings::password())->toBe('secret-password')
        ->and(Setting::get(MailSettings::KEY_PASSWORD))->not->toContain('secret-password');
});

test('an empty password field keeps the stored password', function () {
    MailSettings::store([...MailSettings::values(), 'enabled' => true, 'host' => 'smtp.example.com', 'from_address' => 'radio@example.com'], 'kept');

    Livewire::actingAs($this->admin)
        ->test(Settings::class)
        ->set('mailHost', 'mail.example.com')
        ->call('saveMail')
        ->assertHasNoErrors();

    Setting::flushMemo();

    expect(MailSettings::password())->toBe('kept')
        ->and(MailSettings::values()['host'])->toBe('mail.example.com');
});

test('the stored password can be removed', function () {
    MailSettings::store([...MailSettings::values(), 'enabled' => true, 'host' => 'smtp.example.com', 'from_address' => 'radio@example.com'], 'old');

    Livewire::actingAs($this->admin)
        ->test(Settings::class)
        ->set('mailForgetPassword', true)
        ->call('saveMail');

    Setting::flushMemo();

    expect(MailSettings::hasPassword())->toBeFalse();
});

test('a mail server needs a host and a sender address', function () {
    Livewire::actingAs($this->admin)
        ->test(Settings::class)
        ->set('mailEnabled', true)
        ->call('saveMail')
        ->assertHasErrors(['mailHost', 'mailFromAddress']);
});

test('stored mail settings replace the environment', function () {
    MailSettings::store([
        ...MailSettings::values(),
        'enabled' => true,
        'host' => 'smtp.example.com',
        'port' => 465,
        'encryption' => 'tls',
        'from_address' => 'radio@example.com',
    ], 'secret');

    MailSettings::apply();

    expect(config('mail.default'))->toBe('smtp')
        ->and(config('mail.mailers.smtp.host'))->toBe('smtp.example.com')
        ->and(config('mail.mailers.smtp.scheme'))->toBe('smtps')
        ->and(config('mail.mailers.smtp.password'))->toBe('secret')
        ->and(config('mail.from.address'))->toBe('radio@example.com');
});

test('the environment stays in charge while the mail settings are off', function () {
    config(['mail.default' => 'log', 'mail.mailers.smtp.host' => 'env-host']);
    MailSettings::store([...MailSettings::values(), 'enabled' => false, 'host' => 'smtp.example.com']);

    MailSettings::apply();

    expect(config('mail.default'))->toBe('log')
        ->and(config('mail.mailers.smtp.host'))->toBe('env-host');
});

test('the test mail goes to the given address', function () {
    Mail::fake();

    Livewire::actingAs($this->admin)
        ->test(Settings::class)
        ->assertSet('mailTestRecipient', $this->admin->email)
        ->set('mailTestRecipient', 'check@example.com')
        ->call('sendTestMail')
        ->assertHasNoErrors();

    Mail::assertSent(TestMail::class, fn (TestMail $mail) => $mail->hasTo('check@example.com'));
});

test('a failing test mail shows the error of the mail server', function () {
    Mail::shouldReceive('to->send')->andThrow(new RuntimeException('Connection refused'));
    Mail::shouldReceive('purge');

    Livewire::actingAs($this->admin)
        ->test(Settings::class)
        ->call('sendTestMail')
        ->assertHasErrors('mailTestRecipient')
        ->assertSee('Connection refused');
});

test('the station sender domain is validated', function () {
    Livewire::actingAs($this->admin)
        ->test(Settings::class)
        ->set('mailStationSender', true)
        ->set('mailStationSenderDomain', 'not a domain')
        ->call('saveMail')
        ->assertHasErrors('mailStationSenderDomain');
});
