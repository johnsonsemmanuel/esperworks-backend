<?php

namespace App\Services;

use App\Models\User;
use App\Models\Business;
use App\Models\Invoice;
use App\Models\Contract;
use App\Models\Client;
use App\Models\NotificationFailure;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Carbon\Carbon;

class NotificationService
{
    /**
     * Maximum retry attempts
     */
    const MAX_RETRY_ATTEMPTS = 3;

    /**
     * Retry delays in minutes (exponential backoff)
     */
    const RETRY_DELAYS = [5, 15, 60]; // 5min, 15min, 1hour

    /**
     * Send notification based on user preferences with retry mechanism
     */
    public static function send(User $user, string $type, array $data = []): bool
    {
        $preferences = $user->notification_preferences ?? [
            'invoice_paid' => true,
            'invoice_viewed' => true,
            'payment_overdue' => true,
            'new_client' => false,
            'weekly_summary' => true,
            'monthly_report' => true,
        ];

        // Check if user has this notification type enabled
        if (!($preferences[$type] ?? false)) {
            return false;
        }

        try {
            $success = self::sendNotification($user, $type, $data);
            
            if ($success) {
                // Clear any previous failures for this notification type
                self::clearFailures($user->id, $type);
                return true;
            } else {
                // Schedule retry
                self::scheduleRetry($user, $type, $data, 1);
                return false;
            }
        } catch (\Exception $e) {
            Log::error("Failed to send notification {$type}: " . $e->getMessage());
            
            // Record failure and schedule retry
            self::recordFailure($user, $type, $data, $e->getMessage());
            self::scheduleRetry($user, $type, $data, 1);
            
            return false;
        }
    }

    /**
     * Send the actual notification
     */
    private static function sendNotification(User $user, string $type, array $data): bool
    {
        switch ($type) {
            case 'invoice_paid':
                return self::sendInvoicePaidNotification($user, $data);
            case 'invoice_viewed':
                return self::sendInvoiceViewedNotification($user, $data);
            case 'payment_overdue':
                return self::sendPaymentOverdueNotification($user, $data);
            case 'new_client':
                return self::sendNewClientNotification($user, $data);
            case 'weekly_summary':
                return self::sendWeeklySummaryNotification($user, $data);
            case 'monthly_report':
                return self::sendMonthlyReportNotification($user, $data);
            default:
                Log::warning("Unknown notification type: {$type}");
                return false;
        }
    }

    /**
     * Record notification failure
     */
    private static function recordFailure(User $user, string $type, array $data, string $error): void
    {
        NotificationFailure::create([
            'user_id' => $user->id,
            'type' => $type,
            'data' => $data,
            'error_message' => $error,
            'attempt' => 1,
            'next_retry_at' => now()->addMinutes(self::RETRY_DELAYS[0]),
        ]);

        SecurityLogger::logSecurityEvent(
            'notification.failed',
            "Notification {$type} failed for user {$user->id}: {$error}",
            $user
        );
    }

    /**
     * Schedule notification retry
     */
    private static function scheduleRetry(User $user, string $type, array $data, int $attempt): void
    {
        if ($attempt > self::MAX_RETRY_ATTEMPTS) {
            // Max attempts reached, notify admin
            self::notifyAdminOfFailure($user, $type);
            return;
        }

        $delay = self::RETRY_DELAYS[$attempt - 1] ?? 60;
        $nextRetryAt = now()->addMinutes($delay);

        // Update or create failure record
        $failure = NotificationFailure::where('user_id', $user->id)
            ->where('type', $type)
            ->where('status', 'pending')
            ->first();

        if ($failure) {
            $failure->update([
                'attempt' => $attempt,
                'next_retry_at' => $nextRetryAt
            ]);
        } else {
            NotificationFailure::create([
                'user_id' => $user->id,
                'type' => $type,
                'data' => $data,
                'attempt' => $attempt,
                'next_retry_at' => $nextRetryAt,
            ]);
        }

        // Queue the retry
        Queue::later($delay, function() use ($user, $type, $data, $attempt) {
            self::retryNotification($user, $type, $data, $attempt);
        });
    }

    /**
     * Retry failed notification
     */
    public static function retryNotification(User $user, string $type, array $data, int $attempt): void
    {
        try {
            $success = self::sendNotification($user, $type, $data);
            
            if ($success) {
                // Mark failure as resolved
                NotificationFailure::where('user_id', $user->id)
                    ->where('type', $type)
                    ->where('status', 'pending')
                    ->update(['status' => 'resolved', 'resolved_at' => now()]);
                
                Log::info("Notification retry successful for {$type} - User {$user->id}");
            } else {
                // Schedule next retry
                self::scheduleRetry($user, $type, $data, $attempt + 1);
            }
        } catch (\Exception $e) {
            Log::error("Notification retry failed for {$type} - User {$user->id}: " . $e->getMessage());
            
            // Update failure record
            $failure = NotificationFailure::where('user_id', $user->id)
                ->where('type', $type)
                ->where('status', 'pending')
                ->first();
            
            if ($failure) {
                $failure->update([
                    'error_message' => $e->getMessage(),
                    'attempt' => $attempt + 1
                ]);
            }
            
            // Schedule next retry
            self::scheduleRetry($user, $type, $data, $attempt + 1);
        }
    }

    /**
     * Clear resolved failures
     */
    private static function clearFailures(int $userId, string $type): void
    {
        NotificationFailure::where('user_id', $userId)
            ->where('type', $type)
            ->where('status', 'pending')
            ->update(['status' => 'resolved', 'resolved_at' => now()]);
    }

    /**
     * Notify admin of persistent notification failures
     */
    private static function notifyAdminOfFailure(User $user, string $type): void
    {
        $failure = NotificationFailure::where('user_id', $user->id)
            ->where('type', $type)
            ->where('status', 'pending')
            ->first();

        if ($failure) {
            $failure->update(['status' => 'failed', 'failed_at' => now()]);
            
            // Send admin notification (implementation depends on your admin notification system)
            Log::critical("Notification system failure: {$type} for user {$user->id} after " . self::MAX_RETRY_ATTEMPTS . " attempts");
            
            SecurityLogger::logSecurityEvent(
                'notification.system_failure',
                "Critical: Notification {$type} failed permanently for user {$user->id}",
                $user
            );
        }
    }

    /**
     * Process pending retries (should be called by a scheduled job)
     */
    public static function processPendingRetries(): int
    {
        $processed = 0;
        
        $failures = NotificationFailure::where('status', 'pending')
            ->where('next_retry_at', '<=', now())
            ->with('user')
            ->get();

        foreach ($failures as $failure) {
            self::retryNotification($failure->user, $failure->type, $failure->data, $failure->attempt);
            $processed++;
        }

        return $processed;
    }

    /**
     * Get notification failure statistics
     */
    public static function getFailureStats(): array
    {
        $total = NotificationFailure::count();
        $pending = NotificationFailure::where('status', 'pending')->count();
        $resolved = NotificationFailure::where('status', 'resolved')->count();
        $failed = NotificationFailure::where('status', 'failed')->count();

        return [
            'total' => $total,
            'pending' => $pending,
            'resolved' => $resolved,
            'failed' => $failed,
            'success_rate' => $total > 0 ? round(($resolved / $total) * 100, 2) : 100
        ];
    }

    /**
     * Clean up old failure records
     */
    public static function cleanupOldFailures(): int
    {
        // Delete failures older than 30 days
        return NotificationFailure::where('created_at', '<', now()->subDays(30))
            ->delete();
    }

    private static function sendInvoicePaidNotification(User $user, array $data)
    {
        $invoice = $data['invoice'] ?? null;
        if (!$invoice) return false;

        try {
            Mail::to($user->email)->send(new \App\Mail\InvoicePaidMail($invoice));
            Log::info("Invoice paid notification sent to {$user->email}");
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to send invoice paid notification: " . $e->getMessage());
            return false;
        }
    }

    private static function sendInvoiceViewedNotification(User $user, array $data)
    {
        $invoice = $data['invoice'] ?? null;
        if (!$invoice) return false;

        try {
            Mail::to($user->email)->send(new \App\Mail\InvoiceViewedMail($invoice));
            Log::info("Invoice viewed notification sent to {$user->email}");
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to send invoice viewed notification: " . $e->getMessage());
            return false;
        }
    }

    private static function sendPaymentOverdueNotification(User $user, array $data)
    {
        $invoice = $data['invoice'] ?? null;
        if (!$invoice) return false;

        try {
            Mail::to($user->email)->send(new \App\Mail\PaymentOverdueMail($invoice));
            Log::info("Payment overdue notification sent to {$user->email}");
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to send payment overdue notification: " . $e->getMessage());
            return false;
        }
    }

    private static function sendNewClientNotification(User $user, array $data)
    {
        $client = $data['client'] ?? null;
        $business = $data['business'] ?? null;
        if (!$client || !$business) return false;

        try {
            Mail::to($user->email)->send(new \App\Mail\NewClientMail($client, $business));
            Log::info("New client notification sent to {$user->email}");
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to send new client notification: " . $e->getMessage());
            return false;
        }
    }

    private static function sendWeeklySummaryNotification(User $user, array $data)
    {
        $business = $data['business'] ?? null;
        if (!$business) return false;

        try {
            Mail::to($user->email)->send(new \App\Mail\WeeklySummaryMail($business));
            Log::info("Weekly summary notification sent to {$user->email}");
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to send weekly summary notification: " . $e->getMessage());
            return false;
        }
    }

    private static function sendMonthlyReportNotification(User $user, array $data)
    {
        $business = $data['business'] ?? null;
        if (!$business) return false;

        try {
            Mail::to($user->email)->send(new \App\Mail\MonthlyReportMail($business));
            Log::info("Monthly report notification sent to {$user->email}");
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to send monthly report notification: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Test email sending functionality
     */
    public static function testEmail(User $user)
    {
        try {
            Mail::to($user->email)->send(new \App\Mail\TestNotificationMail());
            Log::info("Test notification sent to {$user->email}");
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to send test notification: " . $e->getMessage());
            return false;
        }
    }
}
