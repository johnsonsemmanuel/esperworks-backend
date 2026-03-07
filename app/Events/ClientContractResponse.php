<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\Presence\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class ClientContractResponse extends Dispatchable implements ShouldBroadcast
{
    public function __construct(
        public int $contractId,
        public int $clientId,
        public int $businessId,
        public string $action
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('client.' . $this->clientId),
            new PrivateChannel('business.' . $this->businessId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'client-contract-response';
    }
}
