<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ClientContractResponse implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int    $contractId,
        public ?int   $clientId,
        public int    $businessId,
        public string $action,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("business.{$this->businessId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'contract.response';
    }

    public function broadcastWith(): array
    {
        return [
            'contract_id' => $this->contractId,
            'action'      => $this->action,
        ];
    }
}
