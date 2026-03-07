<?php

namespace App\Mail;

use App\Models\Business;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UsageLimitReachedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Business $business,
        public string $resource,
        public int|float $limit,
        public int|float $usage,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'You’ve reached your EsperWorks plan limit',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.usage-limit-reached',
        );
    }
}

