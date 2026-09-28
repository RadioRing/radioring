<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Test mail from the instance settings.
 */
class TestMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('RadioRing test mail'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.test',
        );
    }
}
