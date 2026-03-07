<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use App\Models\Business;
use App\Models\Invoice;
use App\Models\Contract;
use Illuminate\Support\Str;

class AdminNotificationService
{
    public static function create(string $type, string $title, string $message, array $data = [], ?int $userId = null, ?int $businessId = null): Notification
    {
        return Notification::create([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'business_id' => $businessId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => $data,
        ]);
    }

    public static function notifyNewUser(User $user): void
    {
        self::create(
            'user.registered',
            'New User Registration',
            "{$user->name} ({$user->email}) has registered for an account",
            ['user_id' => $user->id, 'email' => $user->email]
        );
    }

    public static function notifyNewBusiness(Business $business): void
    {
        self::create(
            'business.created',
            'New Business Created',
            "New business '{$business->name}' has been created",
            ['business_id' => $business->id, 'business_name' => $business->name]
        );
    }

    public static function notifyHighValueInvoice(Invoice $invoice): void
    {
        $amount = (float) $invoice->total;
        if ($amount >= 1000) { // High value threshold
            self::create(
                'invoice.high_value',
                'High Value Invoice Created',
                "High value invoice #{$invoice->invoice_number} for {$invoice->total} created",
                [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'amount' => $amount,
                    'business_id' => $invoice->business_id
                ]
            );
        }
    }

    public static function notifyOverdueInvoice(Invoice $invoice): void
    {
        self::create(
            'invoice.overdue',
            'Invoice Overdue',
            "Invoice #{$invoice->invoice_number} is overdue with amount {$invoice->total}",
            [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'amount' => $invoice->total,
                'business_id' => $invoice->business_id,
                'due_date' => $invoice->due_date
            ]
        );
    }

    public static function notifyContractSigned(Contract $contract): void
    {
        self::create(
            'contract.signed',
            'Contract Signed',
            "Contract #{$contract->contract_number} has been signed",
            [
                'contract_id' => $contract->id,
                'contract_number' => $contract->contract_number,
                'business_id' => $contract->business_id
            ]
        );
    }

    public static function notifyPaymentIssue(array $payment): void
    {
        self::create(
            'payment.failed',
            'Payment Issue Detected',
            "Payment issue detected for invoice #{$payment['invoice_number']}",
            [
                'payment_id' => $payment['id'],
                'invoice_number' => $payment['invoice_number'],
                'amount' => $payment['amount'],
                'error' => $payment['error'] ?? 'Unknown error'
            ]
        );
    }

    public static function notifySuspiciousActivity(string $activity, array $context): void
    {
        self::create(
            'security.suspicious',
            'Suspicious Activity Detected',
            "Suspicious activity detected: {$activity}",
            array_merge($context, ['timestamp' => now()])
        );
    }

    public static function notifySystemIssue(string $issue, array $context = []): void
    {
        self::create(
            'system.issue',
            'System Issue',
            "System issue detected: {$issue}",
            array_merge($context, ['timestamp' => now()])
        );
    }

    public static function notifyBusinessSuspended(Business $business): void
    {
        self::create(
            'business.suspended',
            'Business Suspended',
            "Business '{$business->name}' has been suspended",
            ['business_id' => $business->id, 'business_name' => $business->name]
        );
    }

    public static function notifyPlanUpgrade(Business $business, string $fromPlan, string $toPlan): void
    {
        self::create(
            'business.upgrade',
            'Plan Upgraded',
            "Business '{$business->name}' upgraded from {$fromPlan} to {$toPlan}",
            [
                'business_id' => $business->id,
                'business_name' => $business->name,
                'from_plan' => $fromPlan,
                'to_plan' => $toPlan
            ]
        );
    }

    public static function getUnreadCount(): int
    {
        return Notification::whereNull('read_at')->count();
    }

    public static function getRecent(int $limit = 10): \Illuminate\Database\Eloquent\Collection
    {
        return Notification::with(['user:id,name', 'business:id,name'])
            ->latest()
            ->limit($limit)
            ->get();
    }

    public static function markAsRead(string $notificationId): bool
    {
        $notification = Notification::find($notificationId);
        if ($notification) {
            $notification->update(['read_at' => now()]);
            return true;
        }
        return false;
    }

    public static function markAllAsRead(): int
    {
        return Notification::whereNull('read_at')->update(['read_at' => now()]);
    }
}
