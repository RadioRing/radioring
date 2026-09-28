<?php

namespace App\Mail;

use App\Models\Station;
use App\Models\StationAlert;
use App\Services\Mail\MailSettings;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Alert or all-clear for a station owner. Not queued on purpose.
 */
class StationAlertMail extends Mailable
{
    public function __construct(
        public Station $station,
        public StationAlert $alert,
        public bool $resolved = false,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->resolved
            ? __('Resolved at :station: :problem', ['station' => $this->station->name, 'problem' => $this->alert->type->label()])
            : __('Alert at :station: :problem', ['station' => $this->station->name, 'problem' => $this->alert->type->label()]);

        $sender = MailSettings::stationSender($this->station);

        return new Envelope(
            from: $sender,
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.station-alert',
            with: [
                'problem' => $this->alert->type->label(),
                'explanation' => $this->alert->type->explanation(),
                'startedAt' => $this->alert->started_at,
                'resolvedAt' => $this->alert->resolved_at,
            ],
        );
    }
}
