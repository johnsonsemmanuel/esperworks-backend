<?php

namespace App\Mail;

use App\Models\Contract;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContractMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Contract $contract,
        public string $type = 'send' // send, reminder, signed, signature_request
    ) {}

    public function envelope(): Envelope
    {
        $subjects = [
            'send' => "{$this->contract->type} from {$this->contract->business->name}: {$this->contract->title}",
            'reminder' => "Reminder: Please sign {$this->contract->title}",
            'signed' => "{$this->contract->title} has been signed",
            'signature_request' => "Action Required: {$this->contract->business->name} is requesting your signature",
        ];

        return new Envelope(
            subject: $subjects[$this->type] ?? $subjects['send'],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: "emails.contract.{$this->type}",
        );
    }
}
