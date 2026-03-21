<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int     $paymentId,
        public ?int    $clientId,
        public int     $businessId,
        public float   $amount,
        public string  $reference,
        public ?string $currency = null,
        public ?string $method   = null,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("business.{$this->businessId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'payment.received';
    }

    public function broadcastWith(): array
    {
        return [
            'payment_id' => $this->paymentId,
            'amount'     => $this->amount,
            'currency'   => $this->currency ?? 'GHS',
            'reference'  => $this->reference,
        ];
    }
}
