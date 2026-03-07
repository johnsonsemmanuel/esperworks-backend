<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\Business;
use App\Models\Invoice;
use App\Models\Contract;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Model;

class ActivityService
{
    public static function log(string $action, ?string $description = null, ?Model $model = null, array $data = []): ActivityLog
    {
        return ActivityLog::create([
            'business_id' => self::getBusinessId(),
            'user_id' => auth()->id(),
            'action' => $action,
            'description' => $description,
            'model_type' => $model ? get_class($model) : null,
            'model_id' => $model?->getKey(),
            'data' => $data ?: null,
            'ip_address' => request()->ip(),
        ]);
    }

    public static function logUserRegistered(User $user): ActivityLog
    {
        return self::log(
            'user.registered',
            "New user {$user->name} ({$user->email}) registered",
            $user,
            ['email' => $user->email]
        );
    }

    public static function logUserLogin(User $user): ActivityLog
    {
        return self::log(
            'user.login',
            "User {$user->name} logged in",
            $user,
            ['email' => $user->email]
        );
    }

    public static function logBusinessCreated(Business $business): ActivityLog
    {
        return self::log(
            'business.created',
            "New business '{$business->name}' created",
            $business,
            ['business_name' => $business->name]
        );
    }

    public static function logInvoiceCreated(Invoice $invoice): ActivityLog
    {
        return self::log(
            'invoice.created',
            "Invoice #{$invoice->invoice_number} created for {$invoice->total}",
            $invoice,
            [
                'invoice_number' => $invoice->invoice_number,
                'total' => $invoice->total,
                'client_name' => $invoice->client?->name
            ]
        );
    }

    public static function logInvoiceSent(Invoice $invoice): ActivityLog
    {
        return self::log(
            'invoice.sent',
            "Invoice #{$invoice->invoice_number} sent to client",
            $invoice,
            [
                'invoice_number' => $invoice->invoice_number,
                'client_email' => $invoice->client?->email
            ]
        );
    }

    public static function logInvoicePaid(Invoice $invoice, Payment $payment): ActivityLog
    {
        return self::log(
            'invoice.paid',
            "Invoice #{$invoice->invoice_number} paid - {$payment->amount}",
            $invoice,
            [
                'invoice_number' => $invoice->invoice_number,
                'payment_amount' => $payment->amount,
                'payment_method' => $payment->method
            ]
        );
    }

    public static function logContractCreated(Contract $contract): ActivityLog
    {
        return self::log(
            'contract.created',
            "Contract #{$contract->contract_number} created",
            $contract,
            [
                'contract_number' => $contract->contract_number,
                'client_name' => $contract->client?->name
            ]
        );
    }

    public static function logContractSigned(Contract $contract): ActivityLog
    {
        return self::log(
            'contract.signed',
            "Contract #{$contract->contract_number} signed",
            $contract,
            [
                'contract_number' => $contract->contract_number,
                'signed_by' => $contract->client_signed_at ? 'client' : 'business'
            ]
        );
    }

    public static function logPaymentReceived(Payment $payment): ActivityLog
    {
        return self::log(
            'payment.success',
            "Payment of {$payment->amount} received",
            $payment,
            [
                'payment_amount' => $payment->amount,
                'payment_method' => $payment->method,
                'invoice_number' => $payment->invoice?->invoice_number
            ]
        );
    }

    public static function logBusinessSuspended(Business $business): ActivityLog
    {
        return self::log(
            'admin.business_suspended',
            "Business '{$business->name}' suspended by admin",
            $business,
            ['business_name' => $business->name]
        );
    }

    public static function logBusinessActivated(Business $business): ActivityLog
    {
        return self::log(
            'admin.user_activated',
            "Business '{$business->name}' activated by admin",
            $business,
            ['business_name' => $business->name]
        );
    }

    public static function logPlanChanged(Business $business, string $oldPlan, string $newPlan): ActivityLog
    {
        return self::log(
            'admin.plan_changed',
            "Business '{$business->name}' plan changed from {$oldPlan} to {$newPlan}",
            $business,
            [
                'business_name' => $business->name,
                'old_plan' => $oldPlan,
                'new_plan' => $newPlan
            ]
        );
    }

    private static function getBusinessId(): ?int
    {
        // Try to get business from route parameter (API context)
        if (request()->route('business')) {
            $business = request()->route('business');
            return $business instanceof \App\Models\Business ? $business->id : (int) $business;
        }

        // Try to get business from authenticated user's first business
        if (auth()->check()) {
            $firstBusiness = auth()->user()->businesses()->first();
            if ($firstBusiness) {
                return $firstBusiness->id;
            }
        }

        // For admin actions, no specific business
        return null;
    }
}
