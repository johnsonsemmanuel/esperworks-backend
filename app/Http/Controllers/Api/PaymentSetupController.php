<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Business;
use App\Services\ManualPaymentService;
use App\Services\PaystackService;
use Illuminate\Http\Request;

class PaymentSetupController extends Controller
{
    protected PaystackService $paystack;

    public function __construct(PaystackService $paystack)
    {
        $this->paystack = $paystack;
    }

    public function getStatus(Business $business)
    {
        $this->authorize('view', $business);

        return response()->json([
            'payment_verified' => (bool) $business->payment_verified,
            'settlement_bank' => $business->settlement_bank,
            'bank_account_number' => $business->bank_account_number ? '****' . substr($business->bank_account_number, -4) : null,
            'bank_account_name' => $business->bank_account_name,
            'bank_code' => $business->bank_code,
            'settlement_type' => $business->settlement_type ?? 'bank',
            'has_subaccount' => !empty($business->paystack_subaccount_code),
            'mobile_money_enabled' => !empty($business->paystack_subaccount_code),
        ]);
    }

    public function listBanks(Request $request)
    {
        $type = $request->query('type', 'nuban');
        $result = $this->paystack->listBanks($type);
        $banks = $result['data'] ?? [];

        return response()->json(['banks' => $banks]);
    }

    public function resolveAccount(Request $request)
    {
        $request->validate([
            'account_number' => 'required|string|min:10|max:13',
            'bank_code' => 'required|string',
        ]);

        $accountNumber = $request->account_number;
        $bankCode = (string) $request->bank_code;

        $result = $this->paystack->resolveAccountNumber($accountNumber, $bankCode);

        if ($result['status'] ?? false) {
            return response()->json([
                'account_name' => $result['data']['account_name'] ?? '',
                'account_number' => $result['data']['account_number'] ?? $accountNumber,
            ]);
        }

        // For Ghana mobile money, Paystack resolve may require E.164 format (233XXXXXXXXX). Try that.
        $momoList = $this->paystack->listBanks('mobile_money');
        $momoBanks = $momoList['data'] ?? [];
        $momoCodes = array_map(function ($b) {
            return (string) ($b['code'] ?? $b['id'] ?? '');
        }, $momoBanks);
        $isMobileMoney = in_array($bankCode, $momoCodes, true);

        if ($isMobileMoney) {
            $digits = preg_replace('/\D/', '', $accountNumber);
            if (strlen($digits) >= 10) {
                $e164 = (strlen($digits) === 10 && $digits[0] === '0')
                    ? '233' . substr($digits, 1)
                    : (preg_match('/^233/', $digits) ? $digits : '233' . ltrim($digits, '0'));
                $result = $this->paystack->resolveAccountNumber($e164, $bankCode);
                if ($result['status'] ?? false) {
                    return response()->json([
                        'account_name' => $result['data']['account_name'] ?? '',
                        'account_number' => $request->account_number,
                    ]);
                }
            }

            // Resolve not available for this MoMo number; require manual name entry for settlements
            return response()->json([
                'message' => 'We couldn\'t verify this mobile money number. Enter the name exactly as registered with your provider below.',
                'code' => 'MOMO_MANUAL_NAME_REQUIRED',
                'account_number' => $accountNumber,
            ], 422);
        }

        return response()->json(['message' => 'Could not resolve account. Please check the details.'], 422);
    }

    public function setupAccount(Request $request, Business $business)
    {
        $this->authorize('update', $business);

        $request->validate([
            'bank_code' => 'required|string',
            'bank_name' => 'required|string',
            'account_number' => 'required|string|min:10|max:13',
            'account_name' => 'required|string',
            'settlement_type' => 'nullable|in:bank,mobile_money',
        ]);

        $settlementType = $request->settlement_type ?? 'bank';

        if ($business->paystack_subaccount_code) {
            $result = $this->paystack->updateSubaccount($business->paystack_subaccount_code, [
                'business_name' => $business->name,
                'bank_code' => $request->bank_code,
                'account_number' => $request->account_number,
            ]);
        } else {
            $result = $this->paystack->createSubaccount([
                'business_name' => $business->name,
                'bank_code' => $request->bank_code,
                'account_number' => $request->account_number,
                'percentage_charge' => 99.5,
                'description' => "EsperWorks subaccount for {$business->name}",
                'email' => $business->email ?? $business->owner->email,
                'phone' => $business->phone,
            ]);
        }

        if (!($result['status'] ?? false)) {
            $message = $result['message'] ?? 'Payment setup failed. Please try again.';
            return response()->json([
                'message' => $message,
                'error' => $result['error'] ?? null,
            ], 422);
        }

        $subaccountCode = $result['data']['subaccount_code'] ?? $business->paystack_subaccount_code;

        $business->update([
            'paystack_subaccount_code' => $subaccountCode,
            'settlement_bank' => $request->bank_name,
            'bank_account_number' => $request->account_number,
            'bank_account_name' => $request->account_name,
            'bank_code' => $request->bank_code,
            'settlement_type' => $settlementType,
            'payment_verified' => !empty($subaccountCode),
        ]);

        ActivityLog::log('payment.setup', "Payment account setup ({$settlementType}) for {$business->name}", $business);

        return response()->json([
            'message' => 'Payment account setup successfully',
            'payment_verified' => (bool) $business->payment_verified,
            'bank_account_name' => $business->bank_account_name,
            'settlement_type' => $settlementType,
        ]);
    }

    public function initializePayment(Request $request, Business $business)
    {
        $this->authorize('view', $business);

        $request->validate([
            'invoice_id' => 'required|exists:invoices,id',
            'email' => 'required|email',
        ]);

        $invoice = $business->invoices()->findOrFail($request->invoice_id);
        $balanceDue = $invoice->total - $invoice->amount_paid;

        if ($balanceDue <= 0) {
            return response()->json(['message' => 'Invoice is already fully paid'], 422);
        }

        if (!$business->payment_verified || !$business->paystack_subaccount_code) {
            return response()->json(['message' => 'Business has not set up payment receiving. Contact the business directly.'], 422);
        }

        $reference = 'ESP-' . strtoupper(uniqid()) . '-' . $invoice->id;

        $result = $this->paystack->initializeSplitTransaction([
            'email' => $request->email,
            'amount' => $balanceDue,
            'currency' => 'GHS',
            'reference' => $reference,
            'subaccount_code' => $business->paystack_subaccount_code,
            'callback_url' => config('app.frontend_url') . '/client/dashboard?payment=success',
            'metadata' => [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'business_id' => $business->id,
                'business_name' => $business->name,
            ],
        ]);

        if (!($result['status'] ?? false)) {
            return response()->json(['message' => $result['message'] ?? 'Payment initialization failed'], 422);
        }

        return response()->json([
            'authorization_url' => $result['data']['authorization_url'],
            'reference' => $result['data']['reference'],
            'amount' => $balanceDue,
        ]);
    }

    public function recordManualPayment(Request $request, Business $business)
    {
        $this->authorize('update', $business);

        $request->validate([
            'invoice_id' => 'required|exists:invoices,id',
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|string|in:cash,momo,bank_transfer,cheque,other',
            'reference' => 'nullable|string',
            'notes' => 'nullable|string',
            'payment_date' => 'nullable|date',
        ]);

        try {
            $payment = app(ManualPaymentService::class)->record([
                'business_id' => $business->id,
                'invoice_id' => $request->invoice_id,
                'amount' => $request->amount,
                'method' => $request->payment_method,
                'reference' => $request->reference,
                'paid_at' => $request->payment_date,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $invoice = $payment->invoice;
        return response()->json([
            'message' => 'Payment recorded successfully',
            'payment' => $payment,
            'invoice_status' => $invoice ? $invoice->fresh()->status : null,
        ]);
    }
}
