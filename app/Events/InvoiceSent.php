<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class InvoiceSent implements ShouldBroadcast
{
    use Dispatchable;

    public function __construct(
        public int $invoiceId,
        public int $clientId,
        public int $businessId,
        public string $invoiceNumber,
        public float $total
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('client.' . $this->clientId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'client-notifications';
    }
}
