<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use App\Models\User;

class SecurityLogger
{
    public static function logSecurityEvent(string $event, string $description, ?User $user = null, ?Request $request = null): void
    {
        $context = [
            'event' => $event,
            'description' => $description,
            'timestamp' => now()->toISOString(),
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'user_id' => $user?->id,
            'user_email' => $user?->email,
        ];

        // Log to security channel
        Log::channel('security')->warning($description, $context);

        // Also log to default channel for critical events
        if (in_array($event, ['account.locked', 'brute.force.detected', 'suspicious.activity'])) {
            Log::critical("SECURITY ALERT: {$description}", $context);
        }
    }

    public static function logFailedLogin(string $email, ?Request $request = null): void
    {
        self::logSecurityEvent('auth.failed', "Failed login attempt for email: {$email}", null, $request);
    }

    public static function logAccountLocked(User $user, string $reason, ?Request $request = null): void
    {
        self::logSecurityEvent('account.locked', "Account locked: {$user->email} - Reason: {$reason}", $user, $request);
    }

    public static function logSuspiciousActivity(string $activity, ?User $user = null, ?Request $request = null): void
    {
        self::logSecurityEvent('suspicious.activity', $activity, $user, $request);
    }

    public static function logPaymentSecurity(string $event, string $description, ?User $user = null, ?Request $request = null): void
    {
        self::logSecurityEvent('payment.security', $description, $user, $request);
    }

    public static function logDataAccess(string $resource, string $action, ?User $user = null, ?Request $request = null): void
    {
        self::logSecurityEvent('data.access', "User accessed {$resource}: {$action}", $user, $request);
    }
}
