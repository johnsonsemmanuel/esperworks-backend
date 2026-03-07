<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use App\Mail\TwoFactorCodeMail;
use App\Mail\TwoFactorBackupCodesMail;

class TwoFactorService
{
    /**
     * Generate and send 2FA verification code
     */
    public static function generateCode(User $user): string
    {
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        
        // Store code with expiry (10 minutes)
        Cache::put(
            "2fa_code_{$user->id}",
            [
                'code' => $code,
                'expires_at' => now()->addMinutes(10),
                'attempts' => 0
            ],
            now()->addMinutes(15)
        );

        // Send email with code
        try {
            Mail::to($user->email)->send(new TwoFactorCodeMail($code, $user->name));
            SecurityLogger::logSecurityEvent('2fa.code.generated', "2FA code generated for user", $user);
            return $code;
        } catch (\Exception $e) {
            SecurityLogger::logSecurityEvent('2fa.code.failed', "Failed to send 2FA code: {$e->getMessage()}", $user);
            throw new \Exception('Failed to send verification code');
        }
    }

    /**
     * Verify 2FA code
     */
    public static function verifyCode(User $user, string $code): bool
    {
        $cached = Cache::get("2fa_code_{$user->id}");
        
        if (!$cached) {
            SecurityLogger::logSecurityEvent('2fa.verify.failed', "2FA verification failed - no code found", $user);
            return false;
        }

        // Check expiry
        if (now()->isAfter($cached['expires_at'])) {
            Cache::forget("2fa_code_{$user->id}");
            SecurityLogger::logSecurityEvent('2fa.verify.failed', "2FA verification failed - code expired", $user);
            return false;
        }

        // Check attempts (max 3)
        if ($cached['attempts'] >= 3) {
            Cache::forget("2fa_code_{$user->id}");
            SecurityLogger::logSecurityEvent('2fa.verify.failed', "2FA verification failed - too many attempts", $user);
            return false;
        }

        // Verify code
        if ($cached['code'] === $code) {
            Cache::forget("2fa_code_{$user->id}");
            SecurityLogger::logSecurityEvent('2fa.verify.success', "2FA verification successful", $user);
            return true;
        }

        // Increment attempts
        $cached['attempts']++;
        Cache::put("2fa_code_{$user->id}", $cached, now()->addMinutes(15));
        SecurityLogger::logSecurityEvent('2fa.verify.failed', "2FA verification failed - invalid code", $user);
        
        return false;
    }

    /**
     * Generate backup codes for user
     */
    public static function generateBackupCodes(User $user): array
    {
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $codes[] = strtoupper(Str::random(4) . '-' . Str::random(4));
        }

        // Store hashed backup codes
        $hashedCodes = array_map(function($code) {
            return hash('sha256', $code);
        }, $codes);

        $user->update([
            'two_factor_backup_codes' => $hashedCodes
        ]);

        // Send backup codes via email
        try {
            Mail::to($user->email)->send(new TwoFactorBackupCodesMail($codes, $user->name));
            SecurityLogger::logSecurityEvent('2fa.backup.generated', "2FA backup codes generated", $user);
        } catch (\Exception $e) {
            SecurityLogger::logSecurityEvent('2fa.backup.failed', "Failed to send backup codes: {$e->getMessage()}", $user);
        }

        return $codes;
    }

    /**
     * Verify backup code
     */
    public static function verifyBackupCode(User $user, string $code): bool
    {
        $backupCodes = $user->two_factor_backup_codes ?? [];
        $hashedInput = hash('sha256', strtoupper($code));

        if (in_array($hashedInput, $backupCodes)) {
            // Remove used backup code
            $user->update([
                'two_factor_backup_codes' => array_values(array_diff($backupCodes, [$hashedInput]))
            ]);
            
            SecurityLogger::logSecurityEvent('2fa.backup.used', "2FA backup code used", $user);
            return true;
        }

        SecurityLogger::logSecurityEvent('2fa.backup.failed', "Invalid 2FA backup code used", $user);
        return false;
    }

    /**
     * Check if user has backup codes remaining
     */
    public static function hasBackupCodes(User $user): bool
    {
        return count($user->two_factor_backup_codes ?? []) > 0;
    }

    /**
     * Enable 2FA for user (after verification)
     */
    public static function enable(User $user): void
    {
        $user->update([
            'two_factor_enabled' => true,
            'two_factor_enabled_at' => now()
        ]);

        SecurityLogger::logSecurityEvent('2fa.enabled', "2FA enabled for user", $user);
    }

    /**
     * Disable 2FA for user
     */
    public static function disable(User $user): void
    {
        $user->update([
            'two_factor_enabled' => false,
            'two_factor_enabled_at' => null,
            'two_factor_backup_codes' => null
        ]);

        Cache::forget("2fa_code_{$user->id}");
        SecurityLogger::logSecurityEvent('2fa.disabled', "2FA disabled for user", $user);
    }
}
