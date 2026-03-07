<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WaitlistAdminNotifyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $userEmail,
        public ?string $userPhone,
        public int $totalCount
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "New Waitlist Signup: {$this->userEmail}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.waitlist-admin',
        );
    }
}
