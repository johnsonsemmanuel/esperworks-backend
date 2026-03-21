<?php

namespace App\Services;

use App\Models\Business;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Africa's Talking SMS gateway — plan-gated, monthly quota enforced per business.
 *
 * Plan quotas (mirrors Business::getDefaultPlanLimits 'sms' key):
 *   free       →  3 SMS / month
 *   growth     → 20 SMS / month
 *   pro        → 100 SMS / month
 *   enterprise → unlimited (-1)
 *
 * SMS trigger points wired up elsewhere:
 *   • Invoice sent         → notify client with view link
 *   • Invoice reminder     → overdue reminder to client
 *   • Bill due tomorrow    → remind business owner
 */
class SmsService
{
    /**
     * Attempt to send an SMS to a single recipient.
     *
     * Returns true on success or when SMS is disabled/not configured.
     * Returns false only on definitive quota exhaustion or AT API error.
     */
    public static function send(Business $business, string $phone, string $message): bool
    {
        $apiKey    = config('services.africastalking.api_key');
        $username  = config('services.africastalking.username');
        $senderId  = config('services.africastalking.sender_id', 'EsperWorks');

        // Feature flag — if AT is not configured, silently skip (don't break the caller)
        if (empty($apiKey) || empty($username)) {
            return true;
        }

        // Quota check
        if (!self::canSend($business)) {
            Log::info("SMS quota exhausted for business {$business->id} (plan: {$business->plan})");
            return false;
        }

        $phone = self::normalizePhone($phone);
        if (empty($phone)) {
            Log::warning("SmsService: invalid phone number for business {$business->id}");
            return false;
        }

        try {
            $AT       = new \AfricasTalking\SDK\AfricasTalking($username, $apiKey);
            $sms      = $AT->sms();
            $response = $sms->send([
                'to'      => $phone,
                'message' => $message,
                'from'    => $senderId,
            ]);

            $status = $response['SMSMessageData']['Recipients'][0]['status'] ?? 'Unknown';

            if (str_contains(strtolower($status), 'success') || $status === 'Success') {
                self::incrementMonthlyCount($business);
                return true;
            }

            Log::warning("SmsService: AT returned status '{$status}' for business {$business->id}");
            return false;

        } catch (\Throwable $e) {
            Log::error("SmsService error for business {$business->id}: " . $e->getMessage());
            return false;
        }
    }

    // -----------------------------------------------------------------------

    public static function canSend(Business $business): bool
    {
        $limit = self::getMonthlyLimit($business);
        if ($limit === -1) return true;            // unlimited (enterprise)
        if ($limit === 0)  return false;           // plan has no SMS
        return self::getMonthlyCount($business) < $limit;
    }

    public static function remainingThisMonth(Business $business): int
    {
        $limit = self::getMonthlyLimit($business);
        if ($limit === -1) return PHP_INT_MAX;
        return max(0, $limit - self::getMonthlyCount($business));
    }

    // -----------------------------------------------------------------------
    // Internal helpers

    private static function getMonthlyLimit(Business $business): int
    {
        $limits = Business::getPlanLimitsForPlan($business->plan ?? 'free');
        return (int) ($limits['sms'] ?? 0);
    }

    private static function getMonthlyCount(Business $business): int
    {
        return (int) Cache::get(self::cacheKey($business), 0);
    }

    private static function incrementMonthlyCount(Business $business): void
    {
        $key     = self::cacheKey($business);
        $current = (int) Cache::get($key, 0);
        $ttl     = now()->endOfMonth()->addHours(2); // expire early next month
        Cache::put($key, $current + 1, $ttl);
    }

    private static function cacheKey(Business $business): string
    {
        return 'sms_count_' . $business->id . '_' . now()->format('Y_m');
    }

    /**
     * Normalise phone to E.164 format (best effort).
     * Handles: 0244123456 (Ghana) → +233244123456
     * Leaves +234... / +254... etc untouched.
     */
    private static function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/\s+/', '', $phone);

        if (str_starts_with($phone, '+')) {
            return $phone;
        }

        // Ghana local format: 0XXXXXXXXX → +233XXXXXXXXX
        if (preg_match('/^0\d{9}$/', $phone)) {
            return '+233' . substr($phone, 1);
        }

        // If it's already a 9-digit number without leading 0 — assume Ghana
        if (preg_match('/^\d{9}$/', $phone)) {
            return '+233' . $phone;
        }

        // Return as-is; AT will reject it if invalid
        return $phone;
    }
}
