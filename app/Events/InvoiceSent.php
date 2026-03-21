<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InvoiceSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int    $invoiceId,
        public ?int   $clientId,
        public int    $businessId,
        public string $invoiceNumber,
        public float  $total,
    ) {}

    public function broadcastOn(): array
    {
        return [
            // Business owner/team members see it on the dashboard
            new PrivateChannel("business.{$this->businessId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'invoice.sent';
    }

    public function broadcastWith(): array
    {
        return [
            'invoice_id'     => $this->invoiceId,
            'invoice_number' => $this->invoiceNumber,
            'amount'         => $this->total,
        ];
    }
}
