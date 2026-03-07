<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\Presence\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class PaymentReceived extends Dispatchable implements ShouldBroadcast
{
    public function __construct(
        public int $paymentId,
        public int $clientId,
        public int $businessId,
        public float $amount,
        public string $reference
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
