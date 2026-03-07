<?php

namespace App\Contracts;

interface PaymentGateway
{
    /**
     * Initialize a payment transaction with the external gateway.
     *
     * @param  array  $data
     * @return array
     */
    public function initializeTransaction(array $data): array;

    /**
     * Verify a payment transaction with the external gateway.
     *
     * @param  string  $reference
     * @return array
     */
    public function verifyTransaction(string $reference): array;
}

