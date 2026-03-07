<?php

namespace App\Mail;

use App\Models\Business;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PlanExpiringMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Business $business,
        public string $planName,
        public int $daysRemaining,
        public bool $isTrial = false,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->isTrial
            ? 'Your EsperWorks trial is ending soon'
            : 'Your EsperWorks plan is renewing soon';

        return new Envelope(
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.plan-expiring',
        );
    }
}

