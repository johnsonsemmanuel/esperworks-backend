<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewClientMail extends Mailable
{
    use Queueable, SerializesModels;

    public $client;
    public $business;

    public function __construct($client, $business)
    {
        $this->client = $client;
        $this->business = $business;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New Client Added - ' . $this->client->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.new-client',
            with: [
                'client' => $this->client,
                'business' => $this->business,
            ]
        );
    }
}
