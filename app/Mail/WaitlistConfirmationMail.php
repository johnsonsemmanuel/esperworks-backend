<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WaitlistConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $userEmail) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "You're on the EsperWorks Waitlist!",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.waitlist-confirmation',
        );
    }
}
