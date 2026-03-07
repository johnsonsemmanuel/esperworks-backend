<?php

namespace App\Mail;

use App\Models\Client;
use App\Models\Business;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ClientInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Client $client,
        public Business $business,
        public string $temporaryPassword,
        public string $loginUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "You've been invited to {$this->business->name}'s Client Portal",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.client-invite',
        );
    }
}
