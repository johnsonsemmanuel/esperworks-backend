<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Invoice $invoice,
        public string $type = 'send' // send, reminder, receipt, notification, business_reminder
    ) {}

    public function envelope(): Envelope
    {
        $subjects = [
            'send' => "Invoice {$this->invoice->invoice_number} from {$this->invoice->business->name}",
            'reminder' => "Payment Reminder: Invoice {$this->invoice->invoice_number}",
            'receipt' => "Payment Receipt for Invoice {$this->invoice->invoice_number}",
            'notification' => "Invoice Sent: {$this->invoice->invoice_number} delivered to client",
            'business_reminder' => "Payment Reminder Sent: Invoice {$this->invoice->invoice_number}",
        ];

        return new Envelope(
            subject: $subjects[$this->type] ?? $subjects['send'],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: "emails.invoice.{$this->type}",
        );
    }
}
