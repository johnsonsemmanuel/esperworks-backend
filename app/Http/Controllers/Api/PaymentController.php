<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\IdempotencyService;
use App\Services\ManualPaymentService;
use App\Services\PaymentGatewayFactory;
use App\Services\SmsService;
use App\Contracts\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    public function index(Request $request, Business $business)
    {
        $query = $business->payments()->with(['invoice:id,invoice_number,total', 'client:id,name']);

        if ($request->status && $request->status !== 'all') {
            $status = $request->status === 'received' ? 'success' : $request->status;
            $query->where('status', $status);
        }
        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('reference', 'like', "%{$request->search}%")
                    ->orWhereHas('client', fn($q2) => $q2->where('name', 'like', "%{$request->search}%"))
                    ->orWhereHas('invoice', fn($q2) => $q2->where('invoice_number', 'like', "%{$request->search}%"));
            });
        }

        $paginator = $query->latest()->paginate($request->per_page ?? 20);

        $baseQuery = $business->payments();
        $stats = [
            'total_received' => (clone $baseQuery)->where('status', 'success')->sum('amount'),
            'pending' => (clone $baseQuery)->where('status', 'pending')->sum('amount'),
            'mobile_money' => (clone $baseQuery)->where('status', 'success')->whereIn('method', ['momo', 'mobile_money'])->sum('amount'),
            'bank_transfer' => (clone $baseQuery)->where('status', 'success')->whereIn('method', ['bank', 'bank_transfer'])->sum('amount'),
        ];

        return response()->json(array_merge($paginator->toArray(), ['stats' => $stats]));
    }

    public function initiate(Request $request, Business $business)
    {
        $request->validate([
            'invoice_id' => 'required|exists:invoices,id',
            'amount' => 'nullable|numeric|min:1',
            'payment_method' => 'nullable|in:paystack,mobile_money',
            'mobile_money_provider' => 'nullable|required_if:payment_method,mobile_money|in:mtn,vodafone,airteltigo',
            'mobile_money_number' => 'nullable|required_if:payment_method,mobile_money|string|regex:/^0[2-9]\d{8}$/',
        ]);

        $secret = config('services.paystack.secret_key');
        if (empty($secret)) {
            return response()->json(['message' => 'Payment gateway not configured'], 503);
        }

        $invoice = Invoice::where('id', $request->invoice_id)->where('business_id', $business->id)->firstOrFail();
        $invoice->loadMissing('client:id,email');
        if (!$invoice->client || empty($invoice->client->email)) {
            return response()->json(['message' => 'Client email is required to initiate payment'], 422);
        }

        // Overpayment protection
        $requestedAmount = $request->amount ?? $invoice->amountDue();
        $amountDue = $invoice->amountDue();
        
        if ($requestedAmount > $amountDue) {
            return response()->json([
                'message' => 'Payment amount exceeds invoice due amount',
                'requested_amount' => $requestedAmount,
                'amount_due' => $amountDue,
                'overpayment_amount' => $requestedAmount - $amountDue,
                'suggestion' => 'Please adjust the payment amount to match the invoice total.'
            ], 422);
        }

        if ($requestedAmount <= 0) {
            return response()->json([
                'message' => 'Payment amount must be greater than zero',
                'requested_amount' => $requestedAmount,
                'amount_due' => $amountDue
            ], 422);
        }

        $paymentMethod = $request->payment_method ?? 'paystack';
        $reference = 'EW-' . Str::upper(Str::random(12));

        $metadata = [
            'payment_method' => $paymentMethod === 'mobile_money' ? 'mobile_money' : 'paystack',
            'mobile_money_provider' => $request->mobile_money_provider,
            'mobile_money_number' => $request->mobile_money_number,
        ];

        $payment = Payment::create([
            'business_id' => $business->id,
            'invoice_id' => $invoice->id,
            'client_id' => $invoice->client_id,
            'amount' => $requestedAmount,
            'currency' => $invoice->currency,
            'reference' => $reference,
            'status' => 'pending',
            'metadata' => $metadata,
        ]);

        // Paystack: card + mobile money (MTN, Vodafone, AirtelTigo) for GHS; subaccount split
        $gateway = PaymentGatewayFactory::for($business);
        $result = $gateway->initializeTransaction([
            'email' => $invoice->client->email,
            'amount' => $requestedAmount,
            'currency' => $invoice->currency,
            'reference' => $reference,
            'subaccount' => $business->paystack_subaccount_code,
            'metadata' => [
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'business_id' => $business->id,
                'payment_method' => $paymentMethod === 'mobile_money' ? 'mobile_money' : 'paystack',
            ],
        ]);

        if ($result['status'] ?? false) {
            $payment->update([
                'paystack_reference' => $reference,
                'paystack_access_code' => $result['data']['access_code'] ?? null,
                'gateway' => $business->payment_gateway ?? 'paystack',
            ]);

            return response()->json([
                'message' => 'Payment initialized',
                'authorization_url' => $result['data']['authorization_url'],
                'access_code' => $result['data']['access_code'],
                'reference' => $reference,
            ]);
        }

        $payment->update(['status' => 'failed']);
        return response()->json(['message' => 'Failed to initialize payment', 'error' => $result['message'] ?? 'Unknown error'], 422);
    }

    public function verify(Request $request)
    {
        $request->validate(['reference' => 'required|string']);

        // Handle idempotency for payment verification
        return IdempotencyService::handleIdempotency(
            $request,
            auth()->id(),
            'payment_verify',
            function () use ($request) {
                $payment = Payment::where('paystack_reference', $request->reference)
                    ->orWhere('reference', $request->reference)
                    ->firstOrFail();
                $business = $payment->invoice->business ?? Business::find($payment->business_id);
                $gateway = PaymentGatewayFactory::for($business);
                $result = $gateway->verifyTransaction($request->reference);

                // Idempotency: if already verified successfully, return existing result
                if ($payment->status === 'success') {
                    return response()->json(['message' => 'Payment already verified', 'payment' => $payment]);
                }

                if (($result['data']['status'] ?? '') === 'success') {
                    // Enhanced payment reconciliation
                    $paidAmount = (float) ($result['data']['amount'] ?? $payment->amount);
                    $invoiceTotal = (float) $payment->invoice->total;
                    $currentPaid = (float) $payment->invoice->amount_paid;
                    
                    // Validate payment amount matches expected amount
                    if (abs($paidAmount - $payment->amount) > 0.01) {
                        Log::warning("Payment amount mismatch", [
                            'payment_id' => $payment->id,
                            'expected_amount' => $payment->amount,
                            'received_amount' => $paidAmount,
                            'invoice_id' => $payment->invoice_id
                        ]);
                        
                        // Update payment to actual received amount
                        $payment->amount = $paidAmount;
                    }

                    DB::transaction(function () use ($payment, $result, $currentPaid, $invoiceTotal) {
                        $payment->update([
                            'status' => 'success',
                            'method' => $result['data']['channel'] ?? 'paystack',
                            'paid_at' => now(),
                            'metadata' => array_merge($payment->metadata ?? [], $result['data']),
                        ]);

                        // Update invoice payment status with reconciliation
                        $newAmountPaid = $currentPaid + $payment->amount;
                        $invoice = $payment->invoice;
                        
                        // Determine invoice status based on payment reconciliation
                        $newStatus = 'partial';
                        if ($newAmountPaid >= $invoiceTotal - 0.01) { // Allow for rounding
                            $newStatus = 'paid';
                            $newAmountPaid = $invoiceTotal; // Prevent overpayment
                        }
                        
                        $invoice->update([
                            'status' => $newStatus,
                            'amount_paid' => $newAmountPaid,
                            'paid_at' => $newStatus === 'paid' ? now() : $invoice->paid_at,
                        ]);

                        // Log reconciliation details
                        ActivityLog::log('payment.reconciled', 
                            "Payment {$payment->reference} reconciled: {$payment->amount} paid, {$newAmountPaid}/{$invoiceTotal} total, status: {$newStatus}", 
                            $payment->invoice,
                            [
                                'payment_id' => $payment->id,
                                'payment_amount' => $payment->amount,
                                'previous_paid' => $currentPaid,
                                'new_paid' => $newAmountPaid,
                                'invoice_total' => $invoiceTotal,
                                'invoice_status' => $newStatus,
                                'reconciliation_method' => 'payment_verification'
                            ]
                        );
                    });

                    // Non-critical operations outside transaction
                    $invoice = $payment->invoice;
                    if ($invoice && $invoice->isFullyPaid()) {
                        $invoice->load(['business', 'client']);
                        $receiptStatus = $this->dispatchReceipt($invoice);
                    }

                    try {
                        \App\Events\PaymentReceived::dispatch(
                            $payment->id,
                            $payment->client_id,
                            $payment->business_id,
                            floatval($payment->amount),
                            $payment->currency,
                            $payment->method,
                            $payment->reference
                        );
                    } catch (\Exception $e) {
                        \Log::warning('Failed to dispatch PaymentReceived event on verification: ' . $e->getMessage());
                    }

                    ActivityLog::log('payment.success', "Payment of GH₵ {$payment->amount} verified successfully", $payment);

                    return response()->json([
                        'message' => 'Payment verified successfully',
                        'payment' => $payment->load(['invoice:id,invoice_number,total', 'client:id,name'])
                    ]);
                } else {
                    // Handle failed payment
                    $payment->update([
                        'status' => 'failed',
                        'metadata' => array_merge($payment->metadata ?? [], [
                            'verification_error' => $result['message'] ?? 'Payment verification failed',
                            'gateway_response' => $result
                        ])
                    ]);

                    ActivityLog::log('payment.failed', "Payment verification failed: " . ($result['message'] ?? 'Unknown error'), $payment);

                    return response()->json([
                        'message' => 'Payment verification failed',
                        'error' => $result['message'] ?? 'Payment verification failed',
                        'payment' => $payment
                    ], 422);
                }
            }
        );
    }

    /**
     * Business-scoped: retry verification for a specific payment using its stored reference.
     */
    public function retryVerify(Request $request, Business $business, Payment $payment)
    {
        if ($payment->business_id !== $business->id) {
            return response()->json(['message' => 'Payment not found'], 404);
        }

        if ($payment->status === 'success') {
            return response()->json(['message' => 'Payment already verified', 'payment' => $payment]);
        }

        $reference = $payment->paystack_reference ?: $payment->reference;
        if (!$reference) {
            return response()->json(['message' => 'No reference available to verify this payment'], 422);
        }

        $business = $payment->invoice->business ?? Business::find($payment->business_id);
        $gateway = PaymentGatewayFactory::for($business);
        $result = $gateway->verifyTransaction($reference);

        if (($result['data']['status'] ?? '') === 'success') {
            DB::transaction(function () use ($payment, $result) {
                $payment->update([
                    'status' => 'success',
                    'method' => $result['data']['channel'] ?? 'paystack',
                    'paid_at' => now(),
                    'metadata' => $result['data'],
                ]);

                $invoice = $payment->invoice;
                if ($invoice) {
                    $totalPaid = $invoice->payments()->where('status', 'success')->sum('amount');
                    $invoice->update(['amount_paid' => $totalPaid]);

                    if ($invoice->isFullyPaid()) {
                        $invoice->markAsPaid();
                    }
                }
            });

            $invoice = $payment->invoice;
            if ($invoice && $invoice->isFullyPaid()) {
                $invoice->load(['business', 'client']);
                $receiptStatus = $this->dispatchReceipt($invoice);
            }

            try {
                \App\Events\PaymentReceived::dispatch(
                    $payment->id,
                    $payment->client_id,
                    $payment->business_id,
                    floatval($payment->amount),
                    $payment->reference
                );
            } catch (\Exception $e) {
                \Log::warning('Failed to dispatch PaymentReceived event on retry: ' . $e->getMessage());
            }

            ActivityLog::log('payment.success.retry', "Payment of GH₵ {$payment->amount} verified on retry", $payment);

            return response()->json([
                'message' => 'Payment verified successfully',
                'payment' => $payment,
                'receipt_delivery' => $receiptStatus,
            ]);
        }

        $payment->update(['status' => 'failed', 'metadata' => $result['data'] ?? null]);
        return response()->json([
            'message' => 'Payment verification failed',
            'code' => 'payment.gateway.verification_failed',
        ], 422);
    }

    public function webhook(Request $request)
    {
        $secret = config('services.paystack.secret_key');
        if (empty($secret)) {
            \Log::error('Paystack webhook: PAYSTACK_SECRET_KEY not set', [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent()
            ]);
            return response()->json(['message' => 'Webhook not configured'], 503);
        }

        $payload = $request->all();
        $signature = $request->header('x-paystack-signature');
        $rawBody = $request->getContent();

        // Enhanced signature verification with timing attack protection
        if (empty($signature)) {
            \Log::warning('Paystack webhook: Missing signature', [
                'ip' => $request->ip(),
                'payload_preview' => substr($rawBody, 0, 100)
            ]);
            SecurityLogger::logSecurityEvent(
                'webhook.signature_missing',
                'Webhook received without signature',
                null,
                $request
            );
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $expectedSignature = hash_hmac('sha512', $rawBody, $secret);
        
        // Use hash_equals for timing-attack-safe comparison
        if (!hash_equals($expectedSignature, $signature)) {
            \Log::warning('Paystack webhook: Invalid signature', [
                'ip' => $request->ip(),
                'signature_provided' => substr($signature, 0, 20) . '...',
                'payload_preview' => substr($rawBody, 0, 100)
            ]);
            SecurityLogger::logSecurityEvent(
                'webhook.signature_invalid',
                'Webhook received with invalid signature - possible attack',
                null,
                $request
            );
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $event = $payload['event'] ?? '';
        $data = $payload['data'] ?? [];
        
        // Webhook idempotency - prevent replay attacks
        $webhookId = $data['id'] ?? null;
        if ($webhookId) {
            $cacheKey = "webhook_processed_{$webhookId}";
            if (\Cache::has($cacheKey)) {
                \Log::info('Paystack webhook: Duplicate webhook ignored (idempotency)', [
                    'webhook_id' => $webhookId,
                    'event' => $event
                ]);
                return response()->json(['message' => 'ok']); // Return success for duplicate
            }
            // Mark as processed for 24 hours
            \Cache::put($cacheKey, true, now()->addHours(24));
        }

        // Log webhook receipt
        \Log::info('Paystack webhook received', [
            'event' => $event,
            'reference' => $data['reference'] ?? null,
            'webhook_id' => $webhookId,
            'ip' => $request->ip()
        ]);

        if ($event === 'charge.success') {
            $reference = $data['reference'] ?? '';
            
            if (empty($reference)) {
                \Log::warning('Paystack webhook: Missing reference in charge.success event');
                return response()->json(['message' => 'ok']); // Don't fail webhook
            }
            
            $payment = Payment::where('paystack_reference', $reference)->first();

            if (!$payment) {
                \Log::warning('Paystack webhook: Payment not found', [
                    'reference' => $reference,
                    'event' => $event
                ]);
                return response()->json(['message' => 'ok']); // Don't fail webhook
            }

            if ($payment->status === 'success') {
                \Log::info('Paystack webhook: Payment already processed', [
                    'payment_id' => $payment->id,
                    'reference' => $reference
                ]);
                return response()->json(['message' => 'ok']); // Already processed
            }

            try {
                DB::transaction(function () use ($payment, $data) {
                    $payment->update([
                        'status' => 'success',
                        'method' => $data['channel'] ?? 'paystack',
                        'paid_at' => now(),
                        'metadata' => $data,
                    ]);

                    $invoice = $payment->invoice;
                    if ($invoice) {
                        $totalPaid = $invoice->payments()->where('status', 'success')->sum('amount');
                        $invoice->update(['amount_paid' => $totalPaid]);

                        if ($invoice->isFullyPaid()) {
                            $invoice->markAsPaid();
                        }
                    }
                });

                // Non-critical operations outside transaction
                $invoice = $payment->invoice;
                if ($invoice && $invoice->isFullyPaid()) {
                    $invoice->load(['business', 'client']);
                    $receiptStatus = $this->dispatchReceipt($invoice);
                }

                try {
                    \App\Events\PaymentReceived::dispatch(
                        $payment->id,
                        $payment->client_id,
                        $payment->business_id,
                        floatval($payment->amount),
                        $payment->currency ?? 'GHS',
                        $payment->method ?? 'paystack',
                        $payment->reference
                    );
                } catch (\Exception $e) {
                    \Log::warning('Failed to dispatch PaymentReceived event in webhook: ' . $e->getMessage());
                }

                ActivityLog::log('payment.webhook.success', 
                    "Payment {$reference} processed via webhook", 
                    $payment
                );

                return response()->json(['message' => 'ok']);
                
            } catch (\Exception $e) {
                \Log::error('Paystack webhook: Transaction failed', [
                    'reference' => $reference,
                    'payment_id' => $payment->id ?? null,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                // Mark payment as failed
                if ($payment) {
                    $payment->update([
                        'status' => 'failed',
                        'metadata' => array_merge($payment->metadata ?? [], [
                            'webhook_error' => $e->getMessage(),
                            'webhook_timestamp' => now()->toIso8601String()
                        ])
                    ]);
                }
                
                // Return 200 to prevent Paystack retries for application errors
                return response()->json(['message' => 'ok']);
            }
        }

        return response()->json(['message' => 'ok']);
    }

    /**
     * Business-scoped gateway health check for Paystack configuration and connectivity.
     */
    public function gatewayStatus(Business $business)
    {
        $secret = config('services.paystack.secret_key');
        $configured = !empty($secret);

        $ping = null;
        if ($configured) {
            /** @var PaystackService $paystack */
            $paystack = app(PaystackService::class);
            $result = $paystack->listBanks('nuban');
            $ping = [
                'ok' => (bool)($result['status'] ?? false),
                'message' => $result['message'] ?? null,
            ];
        }

        return response()->json([
            'paystack_configured' => $configured,
            'business_has_subaccount' => !empty($business->paystack_subaccount_code),
            'payment_verified' => (bool)$business->payment_verified,
            'ping' => $ping,
        ]);
    }

    public function recordManual(Request $request, Business $business)
    {
        $request->validate([
            'invoice_id' => 'required|exists:invoices,id',
            'amount' => 'required|numeric|min:0.01',
            'method' => 'required|string',
            'reference' => 'nullable|string|max:50',
            'paid_at' => 'nullable|date',
        ]);

        $invoice = Invoice::where('id', $request->invoice_id)
            ->where('business_id', $business->id)
            ->firstOrFail();

        // Overpayment protection for manual payments
        $amountDue = $invoice->amountDue();
        $requestedAmount = floatval($request->amount);
        
        if ($requestedAmount > $amountDue) {
            return response()->json([
                'message' => 'Payment amount exceeds invoice due amount',
                'requested_amount' => $requestedAmount,
                'amount_due' => $amountDue,
                'overpayment_amount' => $requestedAmount - $amountDue,
                'suggestion' => 'Please adjust the payment amount to match the invoice total.'
            ], 422);
        }

        if ($requestedAmount <= 0) {
            return response()->json([
                'message' => 'Payment amount must be greater than zero',
                'requested_amount' => $requestedAmount,
                'amount_due' => $amountDue
            ], 422);
        }

        try {
            $payment = app(ManualPaymentService::class)->record([
                'business_id' => $business->id,
                'invoice_id' => $request->invoice_id,
                'amount' => $requestedAmount,
                'method' => $request->input('method'),
                'reference' => $request->input('reference'),
                'paid_at' => $request->input('paid_at'),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $invoice = $payment->invoice;
        return response()->json([
            'message' => 'Payment recorded',
            'payment' => $payment,
            'invoice_status' => $invoice ? $invoice->fresh()->status : null,
        ], 201);
    }

    public function receipt(Business $business, Payment $payment)
    {
        if ($payment->business_id !== $business->id) {
            return response()->json(['message' => 'Payment not found'], 404);
        }

        if ($payment->status !== 'success') {
            return response()->json(['message' => 'Receipt only available for successful payments'], 422);
        }

        return app(\App\Services\PdfService::class)->streamReceiptPdf($payment);
    }

    public function clientReceipt(Request $request, Payment $payment)
    {
        // Use the sanctum-authenticated user from the request
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Authentication required'], 401);
        }

        // Verify the client owns this payment using proper relationship
        $clientIds = $user->clientProfiles()->pluck('id');
        if (!$clientIds->contains($payment->client_id)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($payment->status !== 'success') {
            return response()->json(['message' => 'Receipt only available for successful payments'], 422);
        }

        return app(\App\Services\PdfService::class)->streamReceiptPdf($payment);
    }

    // Public: initiate payment for client-facing payment pages (accepts signing_token or legacy invoice_id)
    public function initiatePublic(Request $request)
    {
        $request->validate([
            'signing_token' => 'nullable|string',
            'invoice_id' => 'nullable|integer',
            'amount' => 'nullable|numeric|min:1',
        ]);

        // Try token-based lookup first, fall back to ID
        $invoice = null;
        if ($request->signing_token) {
            $invoice = Invoice::where('signing_token', $request->signing_token)->with(['business', 'client'])->first();
        }
        if (!$invoice && $request->invoice_id) {
            $invoice = Invoice::where('id', $request->invoice_id)->with(['business', 'client'])->first();
        }
        if (!$invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        if ($invoice->status === 'paid') {
            return response()->json(['message' => 'This invoice has already been paid.'], 422);
        }

        if (!$invoice->client_id || !$invoice->client) {
            return response()->json(['message' => 'This invoice has no client. Payment cannot be processed.'], 422);
        }

        if (empty($invoice->client->email)) {
            return response()->json(['message' => 'No email on file for this invoice. Please contact the business to update the client email.'], 422);
        }

        $business = $invoice->business;
        if (!$business->payment_verified || empty($business->paystack_subaccount_code)) {
            return response()->json(['message' => 'This business has not set up payment receiving yet. Please contact them to complete payment setup.'], 422);
        }

        $amount = $request->amount ?? $invoice->amountDue();
        if ($amount < 0.01) {
            return response()->json(['message' => 'No amount due to pay.'], 422);
        }
        $reference = 'EW-' . Str::upper(Str::random(12));

        $payment = Payment::create([
            'business_id' => $invoice->business_id,
            'invoice_id' => $invoice->id,
            'client_id' => $invoice->client_id,
            'amount' => $amount,
            'currency' => $invoice->currency,
            'reference' => $reference,
            'status' => 'pending',
        ]);

        $gateway = PaymentGatewayFactory::for($business);
        $payload = [
            'email' => $invoice->client->email,
            'amount' => $amount,
            'currency' => $invoice->currency,
            'reference' => $reference,
            'subaccount' => $invoice->business->paystack_subaccount_code ?? null,
            'metadata' => [
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'business_id' => $invoice->business_id,
            ],
        ];

        \Log::info('Paystack Init', ['reference' => $reference, 'payment_id' => $payment->id]);

        $result = $gateway->initializeTransaction($payload);

        $status = $result['status'] ?? false;

        if ($status) {
            try {
                $payment->update([
                    'paystack_reference' => $reference,
                    'paystack_access_code' => isset($result['data']['access_code']) ? substr($result['data']['access_code'], 0, 100) : null,
                ]);
            } catch (\Exception $e) {
                \Log::error('Payment DB update failed after Paystack init', ['payment_id' => $payment->id, 'error' => $e->getMessage()]);
                return response()->json(['message' => 'Payment initialized but database update failed. Please contact support.'], 500);
            }

            return response()->json([
                'message' => 'Payment initialized',
                'authorization_url' => $result['data']['authorization_url'],
                'access_code' => $result['data']['access_code'],
                'reference' => $reference,
                'payment_id' => $payment->id,
            ]);
        }

        $payment->update(['status' => 'failed']);
        $paystackMessage = $result['message'] ?? $result['error'] ?? 'Unknown error';
        return response()->json([
            'message' => 'Failed to initialize payment. ' . (is_string($paystackMessage) ? $paystackMessage : 'Please try again or contact the business.'),
            'error' => $paystackMessage,
            'code' => 'payment.gateway.initialization_failed',
        ], 422);
    }

    public function webhookFlutterwave(Request $request)
    {
        $signature = $request->header('verif-hash', '');
        $flutterwave = app(\App\Services\FlutterwaveService::class);

        if (!$flutterwave->verifyWebhook($signature, $request->getContent())) {
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $event = $request->input('event');
        $data  = $request->input('data', []);

        if ($event === 'charge.completed' && ($data['status'] ?? '') === 'successful') {
            $txRef = $data['tx_ref'] ?? null;
            if ($txRef) {
                $payment = Payment::where('reference', $txRef)
                    ->orWhere('paystack_reference', $txRef)
                    ->first();
                if ($payment && $payment->status !== 'success') {
                    $payment->update([
                        'status'                => 'success',
                        'gateway_transaction_id' => $data['id'] ?? null,
                        'paid_at'               => now(),
                        'amount'                => $data['amount'] ?? $payment->amount,
                    ]);
                    if ($payment->invoice) {
                        $payment->invoice->increment('amount_paid', $payment->amount);
                        if ($payment->invoice->fresh()->amount_paid >= $payment->invoice->total) {
                            $payment->invoice->update(['status' => 'paid']);
                        }
                    }
                }
            }
        }

        return response()->json(['status' => 'ok']);
    }

    public function setGateway(Request $request, Business $business)
    {
        $this->authorize('update', $business);
        $request->validate(['gateway' => 'required|in:paystack,flutterwave']);
        $business->update(['payment_gateway' => $request->gateway]);
        return response()->json(['message' => 'Gateway updated', 'gateway' => $request->gateway]);
    }

    private function dispatchReceipt(Invoice $invoice)
    {
        try {
            Mail::to($invoice->client->email)->send(new InvoiceMail($invoice, 'receipt'));
            $status = 'sent';
        } catch (\Exception $e) {
            \Log::warning('Failed to send payment receipt email: ' . $e->getMessage());
            $status = 'failed';
        }

        // SMS confirmation to client on payment received
        if (!empty($invoice->client?->phone) && $invoice->business) {
            $currency = $invoice->currency ?? 'GHS';
            $symbol   = match ($currency) { 'GHS' => 'GH₵', 'USD' => '$', 'EUR' => '€', 'GBP' => '£', default => $currency . ' ' };
            $message  = "Payment confirmed! {$invoice->business->name} received {$symbol}"
                . number_format(floatval($invoice->total), 2)
                . " for Invoice #{$invoice->invoice_number}. Thank you.";
            SmsService::send($invoice->business, $invoice->client->phone, $message);
        }

        return $status;
    }
}
