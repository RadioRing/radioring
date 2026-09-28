<?php

namespace App\Livewire\Admin;

use App\Enums\AppMode;
use App\Mail\TestMail;
use App\Models\Tenant;
use App\Services\Mail\MailSettings;
use App\Support\StereoToolTerms;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

#[Title('Instanz-Einstellungen')]
class Settings extends Component
{
    public string $mode = '';

    /** Bound to the licence checkbox, not persisted until the operator confirms. */
    public bool $stereoToolTermsAgreed = false;

    public bool $mailEnabled = false;

    public string $mailHost = '';

    public int $mailPort = 587;

    public string $mailEncryption = MailSettings::ENCRYPTION_STARTTLS;

    public string $mailUsername = '';

    /** Empty keeps the stored password, which never goes back to the browser. */
    public string $mailPassword = '';

    public bool $mailForgetPassword = false;

    public string $mailFromAddress = '';

    public string $mailFromName = '';

    public bool $mailStationSender = false;

    public string $mailStationSenderDomain = '';

    public string $mailTestRecipient = '';

    public function mount(): void
    {
        $this->mode = AppMode::current()->value;
        $this->stereoToolTermsAgreed = StereoToolTerms::accepted();

        $mail = MailSettings::values();
        $this->mailEnabled = $mail['enabled'];
        $this->mailHost = $mail['host'];
        $this->mailPort = $mail['port'];
        $this->mailEncryption = $mail['encryption'];
        $this->mailUsername = $mail['username'];
        $this->mailFromAddress = $mail['from_address'];
        $this->mailFromName = $mail['from_name'];
        $this->mailStationSender = $mail['station_sender'];
        $this->mailStationSenderDomain = $mail['station_sender_domain'];
        $this->mailTestRecipient = (string) auth()->user()->email;
    }

    #[Computed]
    public function mailHasPassword(): bool
    {
        return MailSettings::hasPassword();
    }

    /**
     * Example station sender for the form hint.
     */
    #[Computed]
    public function stationSenderExample(): ?string
    {
        $domain = trim($this->mailStationSenderDomain);

        if ($domain === '' && str_contains($this->mailFromAddress, '@')) {
            $domain = Str::afterLast(trim($this->mailFromAddress), '@');
        }

        $domain = $domain !== '' ? $domain : MailSettings::stationSenderDomain();

        return $domain ? 'my-station-noreply@'.Str::lower($domain) : null;
    }

    public function saveMail(): void
    {
        $this->validate($this->mailRules());

        MailSettings::store($this->mailValues(), $this->mailPasswordToStore());

        $this->reset('mailPassword', 'mailForgetPassword');
        unset($this->mailHasPassword);

        $this->dispatch('notify', message: __('Mail settings saved.'), type: 'success');
    }

    /**
     * Sends with the unsaved form values and shows the SMTP error in the form.
     */
    public function sendTestMail(): void
    {
        $this->validate([
            ...$this->mailRules(),
            'mailTestRecipient' => ['required', 'email'],
        ]);

        MailSettings::apply(
            $this->mailValues(),
            $this->mailPassword !== '' ? $this->mailPassword : ($this->mailForgetPassword ? null : MailSettings::password()),
        );

        try {
            Mail::to($this->mailTestRecipient)->send(new TestMail);
        } catch (Throwable $e) {
            $this->addError('mailTestRecipient', __('Sending failed: :error', ['error' => $e->getMessage()]));

            return;
        } finally {
            // Restore the stored settings.
            MailSettings::apply();
        }

        $this->dispatch('notify',
            message: __('Test mail sent to :address.', ['address' => $this->mailTestRecipient]),
            type: 'success',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function mailRules(): array
    {
        return [
            'mailEnabled' => ['boolean'],
            'mailHost' => [Rule::requiredIf($this->mailEnabled), 'nullable', 'string', 'max:255'],
            'mailPort' => ['required', 'integer', 'between:1,65535'],
            'mailEncryption' => ['required', Rule::in([MailSettings::ENCRYPTION_STARTTLS, MailSettings::ENCRYPTION_TLS])],
            'mailUsername' => ['nullable', 'string', 'max:255'],
            'mailPassword' => ['nullable', 'string', 'max:255'],
            'mailFromAddress' => [Rule::requiredIf($this->mailEnabled), 'nullable', 'email', 'max:255'],
            'mailFromName' => ['nullable', 'string', 'max:255'],
            'mailStationSender' => ['boolean'],
            'mailStationSenderDomain' => ['nullable', 'string', 'max:255', 'regex:/^(?!-)[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)+$/'],
        ];
    }

    /**
     * @return array{enabled: bool, host: string, port: int, encryption: string, username: string, from_address: string, from_name: string, station_sender: bool, station_sender_domain: string}
     */
    private function mailValues(): array
    {
        return [
            'enabled' => $this->mailEnabled,
            'host' => trim($this->mailHost),
            'port' => $this->mailPort,
            'encryption' => $this->mailEncryption,
            'username' => trim($this->mailUsername),
            'from_address' => trim($this->mailFromAddress),
            'from_name' => trim($this->mailFromName),
            'station_sender' => $this->mailStationSender,
            'station_sender_domain' => trim($this->mailStationSenderDomain),
        ];
    }

    private function mailPasswordToStore(): ?string
    {
        if ($this->mailPassword !== '') {
            return $this->mailPassword;
        }

        return $this->mailForgetPassword ? '' : null;
    }

    #[Computed]
    public function tenantCount(): int
    {
        return Tenant::count();
    }

    /**
     * The tenant that new registrations would join once standalone is active.
     */
    #[Computed]
    public function standaloneTenantName(): ?string
    {
        return Tenant::query()->oldest('id')->value('name');
    }

    /**
     * Would switching to standalone change where new registrations land? Only relevant
     * once more than one tenant exists. The operator gets a warning first.
     */
    #[Computed]
    public function switchingToStandaloneIsAmbiguous(): bool
    {
        return AppMode::current()->isCloud() && $this->tenantCount() > 1;
    }

    #[Computed]
    public function stereoToolTermsAccepted(): bool
    {
        return StereoToolTerms::accepted();
    }

    #[Computed]
    public function stereoToolAcceptance(): ?string
    {
        $at = StereoToolTerms::acceptedAt();

        if (! $at) {
            return null;
        }

        return __('Accepted on :date by :name.', [
            'date' => $at->isoFormat('LLL'),
            'name' => StereoToolTerms::acceptedBy()?->name ?? __('a deleted account'),
        ]);
    }

    /**
     * Records instance-wide acceptance of the Thimeo licence. Until this happens, no
     * station can have Stereo Tool enabled (see Admin\Stations::toggleStereoTool).
     */
    public function acceptStereoToolTerms(): void
    {
        if (! $this->stereoToolTermsAgreed) {
            $this->addError('stereoToolTermsAgreed', __('Please confirm that you accept the Stereo Tool licence.'));

            return;
        }

        StereoToolTerms::accept(auth()->user());

        unset($this->stereoToolTermsAccepted, $this->stereoToolAcceptance);

        $this->dispatch('notify', message: __('Stereo Tool licence accepted.'), type: 'success');
    }

    /**
     * Withdraws acceptance. Stereo Tool is switched off on every station, because running
     * it on a licence the instance no longer accepts is exactly what acceptance prevents.
     */
    public function revokeStereoToolTerms(): void
    {
        StereoToolTerms::revoke();

        $this->stereoToolTermsAgreed = false;

        unset($this->stereoToolTermsAccepted, $this->stereoToolAcceptance);

        $this->dispatch('notify',
            message: __('Stereo Tool licence withdrawn and disabled on all stations.'),
            type: 'success',
        );
    }

    public function save(): void
    {
        $this->validate([
            'mode' => ['required', 'in:standalone,cloud'],
        ]);

        $target = AppMode::from($this->mode);

        if ($target === AppMode::current()) {
            return;
        }

        AppMode::switchTo($target);

        unset($this->tenantCount, $this->standaloneTenantName, $this->switchingToStandaloneIsAmbiguous);

        $this->dispatch('notify',
            message: __('Operating mode switched to :mode.', ['mode' => $target->label()]),
            type: 'success',
        );
    }

    public function render()
    {
        return view('livewire.admin.settings', [
            'modes' => AppMode::cases(),
            'currentMode' => AppMode::current(),
            'stereoToolLicenceUrl' => StereoToolTerms::licenceUrl(),
        ])->layout('layouts.app');
    }
}
