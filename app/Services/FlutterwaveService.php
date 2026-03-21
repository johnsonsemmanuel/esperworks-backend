<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FlutterwaveService implements PaymentGateway
{
    protected string $baseUrl;
    protected string $secretKey;

    public function __construct()
    {
        $this->baseUrl    = config('services.flutterwave.base_url', 'https://api.flutterwave.com/v3');
        $this->secretKey  = config('services.flutterwave.secret_key', '');
    }

    protected function request()
    {
        return Http::withToken($this->secretKey)
            ->acceptJson()
            ->baseUrl($this->baseUrl);
    }

    public function initializeTransaction(array $data): array
    {
        if (empty($this->secretKey)) {
            return ['status' => false, 'message' => 'Flutterwave secret key not configured'];
        }

        $txRef     = $data['reference'] ?? ('FLW-' . uniqid());
        $currency  = strtoupper($data['currency'] ?? 'GHS');
        $redirectUrl = $data['callback_url']
            ?? config('app.frontend_url') . '/dashboard/payments/callback';

        // Append gateway query param so frontend knows which gateway completed
        $redirectUrl = $redirectUrl . (str_contains($redirectUrl, '?') ? '&' : '?') . 'gateway=flutterwave';

        $payload = [
            'tx_ref'       => $txRef,
            'amount'       => (float) $data['amount'],
            'currency'     => $currency,
            'redirect_url' => $redirectUrl,
            'customer'     => [
                'email'        => $data['email'],
                'name'         => $data['customer_name'] ?? $data['email'],
                'phonenumber'  => $data['phone'] ?? null,
            ],
            'customizations' => [
                'title'       => $data['title'] ?? 'EsperWorks Invoice Payment',
                'description' => $data['description'] ?? 'Invoice payment',
                'logo'        => config('app.frontend_url') . '/logo.png',
            ],
            'meta' => $data['metadata'] ?? [],
        ];

        // Payment methods per currency
        $momoMap = [
            'GHS' => ['mobilemoneyghan'],
            'UGX' => ['mobilemoneyuganda'],
            'RWF' => ['mobilemoneyrwanda'],
            'ZMW' => ['mobilemoneyzambia'],
            'TZS' => ['mobilemoneytanzania'],
            'XAF' => ['mobilemoneycameroon', 'mobilemoneyfrancophone'],
            'XOF' => ['mobilemoneyfrancophone'],
        ];
        if (isset($momoMap[$currency])) {
            $payload['payment_options'] = implode(',', array_merge(['card'], $momoMap[$currency]));
        }

        try {
            $response = $this->request()->post('/payments', $payload);
            $result   = $response->json();

            if ($response->successful() && ($result['status'] ?? '') === 'success') {
                Log::info('Flutterwave payment initialized', ['tx_ref' => $txRef, 'currency' => $currency]);
                return [
                    'status' => true,
                    'data'   => [
                        'authorization_url' => $result['data']['link'],
                        'access_code'       => $txRef,
                        'reference'         => $txRef,
                    ],
                ];
            }

            Log::error('Flutterwave initialize failed', ['result' => $result]);
            return ['status' => false, 'message' => $result['message'] ?? 'Flutterwave initialization failed'];
        } catch (\Throwable $e) {
            Log::error('Flutterwave request exception', ['error' => $e->getMessage()]);
            return ['status' => false, 'message' => 'Flutterwave request failed: ' . $e->getMessage()];
        }
    }

    public function verifyTransaction(string $reference): array
    {
        if (empty($this->secretKey)) {
            return ['status' => false, 'message' => 'Flutterwave secret key not configured'];
        }

        try {
            // $reference can be a tx_ref (string) or transaction_id (numeric string)
            if (is_numeric($reference)) {
                $response = $this->request()->get("/transactions/{$reference}/verify");
            } else {
                // Search by tx_ref
                $response = $this->request()->get('/transactions', ['tx_ref' => $reference]);
                $json     = $response->json();
                $txns     = $json['data'] ?? [];
                if (empty($txns)) {
                    return ['status' => false, 'message' => 'Transaction not found'];
                }
                // Verify the most recent matching transaction
                $txId     = $txns[0]['id'];
                $response = $this->request()->get("/transactions/{$txId}/verify");
            }

            $result = $response->json();

            if (($result['status'] ?? '') === 'success') {
                $txData = $result['data'];
                $status = strtolower($txData['status'] ?? '');
                return [
                    'status' => true,
                    'data'   => [
                        'status'        => $status === 'successful' ? 'success' : $status,
                        'amount'        => ($txData['amount'] ?? 0),
                        'currency'      => $txData['currency'] ?? '',
                        'reference'     => $txData['tx_ref'] ?? $reference,
                        'gateway_response' => $txData['processor_response'] ?? $status,
                        'paid_at'       => $txData['created_at'] ?? null,
                        'id'            => $txData['id'] ?? null,
                    ],
                ];
            }

            return ['status' => false, 'message' => $result['message'] ?? 'Verification failed'];
        } catch (\Throwable $e) {
            return ['status' => false, 'message' => 'Flutterwave verify failed: ' . $e->getMessage()];
        }
    }

    public function verifyWebhook(string $signature, string $body): bool
    {
        $hash = config('services.flutterwave.webhook_hash', '');
        if (empty($hash)) {
            return false;
        }
        return hash_equals($hash, $signature);
    }
}
