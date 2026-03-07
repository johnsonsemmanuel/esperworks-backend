<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaystackService implements PaymentGateway
{
    protected string $baseUrl;
    protected string $secretKey;

    public function __construct()
    {
        $this->baseUrl = config('services.paystack.payment_url', 'https://api.paystack.co');
        $this->secretKey = config('services.paystack.secret_key', '');
    }

    protected function request()
    {
        return Http::withToken($this->secretKey)
            ->acceptJson()
            ->baseUrl($this->baseUrl);
    }

    public function initializeTransaction(array $data): array
    {
        return CircuitBreakerService::executePaymentGateway(function () use ($data) {
            if (empty($this->secretKey)) {
                return ['status' => false, 'message' => 'Paystack secret key not configured'];
            }

            $payload = [
                'email' => $data['email'],
                'amount' => (int) ($data['amount'] * 100), // Paystack uses kobo/pesewas
                'currency' => $data['currency'] ?? 'GHS',
                'reference' => $data['reference'] ?? null,
                'callback_url' => $data['callback_url'] ?? config('app.frontend_url') . '/dashboard/payments/callback',
                'metadata' => $data['metadata'] ?? [],
            ];

            // Add subaccount for split payments (business gets 99.5%, EsperWorks gets 0.5%)
            if (!empty($data['subaccount'])) {
                $payload['subaccount'] = $data['subaccount'];
            }

            // Ghana: allow card and mobile money (MTN, Vodafone, AirtelTigo) via Paystack
            $currency = $data['currency'] ?? 'GHS';
            if ($currency === 'GHS') {
                $payload['channels'] = $data['channels'] ?? ['card', 'mobile_money'];
            } elseif (!empty($data['channels'])) {
                $payload['channels'] = $data['channels'];
            }

            $response = $this->request()->post('/transaction/initialize', $payload);
            $result = $response->json();

            if ($response->successful()) {
                Log::info('Paystack transaction initialized successfully', [
                    'reference' => $data['reference'],
                    'amount' => $data['amount'],
                    'currency' => $currency,
                ]);
            } else {
                Log::error('Paystack transaction initialization failed', [
                    'reference' => $data['reference'],
                    'error' => $result['message'] ?? 'Unknown error',
                ]);
            }

            return $result;
        });
    }

    public function verifyTransaction(string $reference): array
    {
        return CircuitBreakerService::executePaymentGateway(function () use ($reference) {
            if (empty($this->secretKey)) {
                return ['status' => false, 'message' => 'Paystack secret key not configured'];
            }

            try {
                $response = $this->request()->get("/transaction/verify/{$reference}");
                return $response->json();
            } catch (\Throwable $e) {
                return [
                    'status' => false,
                    'message' => 'Paystack request failed',
                    'error' => $e->getMessage(),
                ];
            }
        });
    }

    public function createCustomer(array $data): array
    {
        if (empty($this->secretKey)) {
            return ['status' => false, 'message' => 'Paystack secret key not configured'];
        }

        try {
            $response = $this->request()->post('/customer', [
                'email' => $data['email'],
                'first_name' => $data['first_name'] ?? null,
                'last_name' => $data['last_name'] ?? null,
                'phone' => $data['phone'] ?? null,
            ]);
            return $response->json();
        } catch (\Throwable $e) {
            return [
                'status' => false,
                'message' => 'Paystack request failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    public function createPlan(array $data): array
    {
        if (empty($this->secretKey)) {
            return ['status' => false, 'message' => 'Paystack secret key not configured'];
        }

        try {
            $response = $this->request()->post('/plan', [
                'name' => $data['name'],
                'amount' => (int) ($data['amount'] * 100),
                'interval' => $data['interval'] ?? 'monthly',
                'currency' => $data['currency'] ?? 'GHS',
            ]);
            return $response->json();
        } catch (\Throwable $e) {
            return [
                'status' => false,
                'message' => 'Paystack request failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    public function createSubscription(array $data): array
    {
        if (empty($this->secretKey)) {
            return ['status' => false, 'message' => 'Paystack secret key not configured'];
        }

        try {
            $response = $this->request()->post('/subscription', [
                'customer' => $data['customer_code'],
                'plan' => $data['plan_code'],
            ]);
            return $response->json();
        } catch (\Throwable $e) {
            return [
                'status' => false,
                'message' => 'Paystack request failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    public function listBanks(string $type = 'nuban'): array
    {
        if (empty($this->secretKey)) {
            return ['status' => false, 'message' => 'Paystack secret key not configured', 'data' => []];
        }

        $params = ['country' => 'ghana'];
        if ($type === 'mobile_money') {
            $params['type'] = 'mobile_money';
        }
        try {
            $response = $this->request()->get('/bank', $params);
            return $response->json();
        } catch (\Throwable $e) {
            return [
                'status' => false,
                'message' => 'Paystack request failed',
                'error' => $e->getMessage(),
                'data' => [],
            ];
        }
    }

    public function refundTransaction(string $reference, ?int $amount = null): array
    {
        if (empty($this->secretKey)) {
            return ['status' => false, 'message' => 'Paystack secret key not configured'];
        }

        $data = ['transaction' => $reference];
        if ($amount) {
            $data['amount'] = $amount;
        }
        try {
            $response = $this->request()->post('/refund', $data);
            return $response->json();
        } catch (\Throwable $e) {
            return [
                'status' => false,
                'message' => 'Paystack request failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    // ── Sub-account (Split Payments) ──

    public function createSubaccount(array $data): array
    {
        if (empty($this->secretKey)) {
            return ['status' => false, 'message' => 'Paystack secret key not configured'];
        }

        try {
            $response = $this->request()->post('/subaccount', [
                'business_name' => $data['business_name'],
                'settlement_bank' => $data['bank_code'],
                'account_number' => $data['account_number'],
                'percentage_charge' => $data['percentage_charge'] ?? 99,
                'description' => $data['description'] ?? '',
                'primary_contact_email' => $data['email'] ?? null,
                'primary_contact_phone' => $data['phone'] ?? null,
            ]);
            return $response->json();
        } catch (\Throwable $e) {
            return [
                'status' => false,
                'message' => 'Paystack request failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    public function updateSubaccount(string $subaccountCode, array $data): array
    {
        if (empty($this->secretKey)) {
            return ['status' => false, 'message' => 'Paystack secret key not configured'];
        }

        $payload = array_filter([
            'business_name' => $data['business_name'] ?? null,
            'settlement_bank' => $data['bank_code'] ?? null,
            'account_number' => $data['account_number'] ?? null,
            'percentage_charge' => $data['percentage_charge'] ?? null,
            'description' => $data['description'] ?? null,
        ]);

        try {
            $response = $this->request()->put("/subaccount/{$subaccountCode}", $payload);
            return $response->json();
        } catch (\Throwable $e) {
            return [
                'status' => false,
                'message' => 'Paystack request failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    public function getSubaccount(string $subaccountCode): array
    {
        if (empty($this->secretKey)) {
            return ['status' => false, 'message' => 'Paystack secret key not configured'];
        }

        try {
            $response = $this->request()->get("/subaccount/{$subaccountCode}");
            return $response->json();
        } catch (\Throwable $e) {
            return [
                'status' => false,
                'message' => 'Paystack request failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    public function resolveAccountNumber(string $accountNumber, string $bankCode): array
    {
        if (empty($this->secretKey)) {
            return ['status' => false, 'message' => 'Paystack secret key not configured'];
        }

        try {
            $response = $this->request()->get('/bank/resolve', [
                'account_number' => $accountNumber,
                'bank_code' => $bankCode,
            ]);
            return $response->json();
        } catch (\Throwable $e) {
            return [
                'status' => false,
                'message' => 'Paystack request failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    public function initializeSplitTransaction(array $data): array
    {
        if (empty($this->secretKey)) {
            return ['status' => false, 'message' => 'Paystack secret key not configured'];
        }

        $payload = [
            'email' => $data['email'],
            'amount' => (int) ($data['amount'] * 100),
            'currency' => $data['currency'] ?? 'GHS',
            'reference' => $data['reference'] ?? null,
            'callback_url' => $data['callback_url'] ?? config('app.frontend_url') . '/client/dashboard/payments/callback',
            'metadata' => $data['metadata'] ?? [],
        ];

        if (!empty($data['subaccount_code'])) {
            $payload['subaccount'] = $data['subaccount_code'];
            $payload['bearer'] = 'subaccount';
        }

        // Ghana: allow card and mobile money via Paystack
        $currency = $data['currency'] ?? 'GHS';
        if ($currency === 'GHS') {
            $payload['channels'] = $data['channels'] ?? ['card', 'mobile_money'];
        } elseif (!empty($data['channels'])) {
            $payload['channels'] = $data['channels'];
        }

        try {
            $response = $this->request()->post('/transaction/initialize', $payload);
            return $response->json();
        } catch (\Throwable $e) {
            return [
                'status' => false,
                'message' => 'Paystack request failed',
                'error' => $e->getMessage(),
            ];
        }
    }
}
