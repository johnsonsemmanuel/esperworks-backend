<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Models\Business;

class PaymentGatewayFactory
{
    public static function for(?Business $business): PaymentGateway
    {
        $gateway = $business?->payment_gateway ?? 'paystack';

        return match ($gateway) {
            'flutterwave' => app(FlutterwaveService::class),
            default       => app(PaystackService::class),
        };
    }
}
