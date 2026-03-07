<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WeeklySummaryMail extends Mailable
{
    use Queueable, SerializesModels;

    public $business;

    public function __construct($business)
    {
        $this->business = $business;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Weekly Summary - ' . $this->business->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.weekly-summary',
            with: [
                'business' => $this->business,
            ]
        );
    }
}
