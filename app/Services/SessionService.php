<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SessionService
{
    /**
     * Maximum concurrent sessions per user
     */
    const MAX_CONCURRENT_SESSIONS = 3;

    /**
     * Maximum concurrent sessions for admin users (more restrictive)
     */
    const MAX_ADMIN_CONCURRENT_SESSIONS = 2;

    /**
     * Session duration in minutes
     */
    const SESSION_DURATION = 480; // 8 hours

    /**
     * Session duration for admin users (shorter for security)
     */
    const ADMIN_SESSION_DURATION = 240; // 4 hours

    /**
     * Create new session with tracking
     */
    public static function createSession(User $user, string $tokenName = 'Web Session'): array
    {
        // Get current active sessions
        $activeSessions = self::getActiveSessions($user->id);
        
        // Use admin-specific limits for admin users
        $maxSessions = $user->isAdmin() ? self::MAX_ADMIN_CONCURRENT_SESSIONS : self::MAX_CONCURRENT_SESSIONS;
        
        // Revoke oldest sessions if limit exceeded
        if (count($activeSessions) >= $maxSessions) {
            $sessionsToRevoke = array_slice($activeSessions, 0, count($activeSessions) - $maxSessions + 1);
            
            foreach ($sessionsToRevoke as $session) {
                $token = $user->tokens()->find($session['token_id']);
                if ($token) {
                    $token->delete();
                    SecurityLogger::logSecurityEvent('session.revoked', 
                        "Session revoked due to limit exceeded", $user);
                }
            }
        }

        // Create new token
        $token = $user->createToken($tokenName);
        
        // Use admin-specific session duration
        $sessionDuration = $user->isAdmin() ? self::ADMIN_SESSION_DURATION : self::SESSION_DURATION;
        
        // Track session
        $sessionData = [
            'token_id' => $token->accessToken->id,
            'token_name' => $tokenName,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now(),
            'last_activity' => now(),
            'is_admin' => $user->isAdmin(),
        ];
        
        Cache::put("session_{$user->id}_{$token->accessToken->id}", $sessionData, now()->addMinutes($sessionDuration));
        
        // Update active sessions list for file cache
        $cacheKey = "active_sessions_{$user->id}";
        $activeSessionIds = Cache::get($cacheKey, []);
        $activeSessionIds[] = $token->accessToken->id;
        // Keep only the most recent sessions (limit based on user type)
        $maxSessions = $user->isAdmin() ? self::MAX_ADMIN_CONCURRENT_SESSIONS : self::MAX_CONCURRENT_SESSIONS;
        $activeSessionIds = array_slice($activeSessionIds, -$maxSessions);
        Cache::put($cacheKey, $activeSessionIds, now()->addMinutes($sessionDuration));
        
        SecurityLogger::logSecurityEvent('session.created', 
            "New session created: {$tokenName}" . ($user->isAdmin() ? " (ADMIN)" : ""), $user);
        
        return [
            'token' => $token->plainTextToken,
            'session_id' => $token->accessToken->id
        ];
    }

    /**
     * Get active sessions for user
     */
    public static function getActiveSessions(int $userId): array
    {
        $sessions = [];
        
        // For file cache, we need to use a different approach
        // Since we can't easily get all keys with file cache, we'll store session tracking differently
        $cacheKey = "active_sessions_{$userId}";
        $activeSessionIds = Cache::get($cacheKey, []);
        
        foreach ($activeSessionIds as $tokenId) {
            $sessionKey = "session_{$userId}_{$tokenId}";
            $sessionData = Cache::get($sessionKey);
            if ($sessionData) {
                $sessions[] = $sessionData;
            }
        }
        
        // Sort by last activity (newest first)
        usort($sessions, function($a, $b) {
            return strtotime($b['last_activity']) - strtotime($a['last_activity']);
        });
        
        return $sessions;
    }

    /**
     * Update session activity
     */
    public static function updateActivity(User $user): void
    {
        if (!$user->currentAccessToken()) {
            return;
        }

        $sessionKey = "session_{$user->id}_{$user->currentAccessToken()->id}";
        $sessionData = Cache::get($sessionKey);
        
        if ($sessionData) {
            $sessionData['last_activity'] = now();
            Cache::put($sessionKey, $sessionData, now()->addMinutes(self::SESSION_DURATION));
        }
    }

    /**
     * Revoke specific session
     */
    public static function revokeSession(User $user, string $tokenId): bool
    {
        $token = $user->tokens()->find($tokenId);
        
        if (!$token) {
            return false;
        }

        // Don't allow revoking current session
        if ($tokenId === $user->currentAccessToken()->id) {
            return false;
        }

        $token->delete();
        Cache::forget("session_{$user->id}_{$tokenId}");
        
        SecurityLogger::logSecurityEvent('session.revoked', 
            "Session revoked by user", $user);
        
        return true;
    }

    /**
     * Revoke all sessions except current
     */
    public static function revokeAllOtherSessions(User $user): int
    {
        $currentTokenId = $user->currentAccessToken()->id;
        $revokedCount = 0;
        
        foreach ($user->tokens()->get() as $token) {
            if ($token->id !== $currentTokenId) {
                $token->delete();
                Cache::forget("session_{$user->id}_{$token->id}");
                $revokedCount++;
            }
        }
        
        SecurityLogger::logSecurityEvent('session.all_others_revoked', 
            "All other sessions revoked", $user);
        
        return $revokedCount;
    }

    /**
     * Revoke all sessions for user
     */
    public static function revokeAllSessions(User $user): int
    {
        $tokens = $user->tokens()->get();
        $revokedCount = 0;
        
        foreach ($tokens as $token) {
            $token->delete();
            Cache::forget("session_{$user->id}_{$token->id}");
            $revokedCount++;
        }
        
        SecurityLogger::logSecurityEvent('session.all_revoked', 
            "All sessions revoked", $user);
        
        return $revokedCount;
    }

    /**
     * Check if session should be revoked due to inactivity
     */
    public static function checkSessionValidity(User $user): bool
    {
        if (!$user->currentAccessToken()) {
            return false;
        }

        $sessionKey = "session_{$user->id}_{$user->currentAccessToken()->id}";
        $sessionData = Cache::get($sessionKey);
        
        if (!$sessionData) {
            return false;
        }

        // Check if session has expired due to inactivity
        $lastActivity = $sessionData['last_activity'];
        if (now()->diffInMinutes($lastActivity) > self::SESSION_DURATION) {
            $user->currentAccessToken()->delete();
            Cache::forget($sessionKey);
            
            SecurityLogger::logSecurityEvent('session.expired', 
                "Session expired due to inactivity", $user);
            
            return false;
        }

        return true;
    }

    /**
     * Detect suspicious session activity
     */
    public static function detectSuspiciousActivity(User $user): array
    {
        $suspicious = [];
        $currentSession = Cache::get("session_{$user->id}_{$user->currentAccessToken()->id}");
        
        if (!$currentSession) {
            return $suspicious;
        }

        $activeSessions = self::getActiveSessions($user->id);
        
        // Check for multiple sessions from different IPs
        $uniqueIps = array_unique(array_column($activeSessions, 'ip_address'));
        if (count($uniqueIps) > 2) {
            $suspicious[] = 'Multiple concurrent sessions from different IP addresses';
        }

        // Check for sessions from different countries (basic check)
        $currentIp = $currentSession['ip_address'];
        foreach ($activeSessions as $session) {
            if ($session['ip_address'] !== $currentIp) {
                // Simple geographic check (could be enhanced with GeoIP)
                if (self::isDifferentGeographicRegion($currentIp, $session['ip_address'])) {
                    $suspicious[] = 'Sessions from different geographic regions';
                    break;
                }
            }
        }

        // Check for rapid session creation
        $recentSessions = array_filter($activeSessions, function($session) {
            return now()->diffInMinutes($session['created_at']) < 5;
        });
        
        if (count($recentSessions) > 2) {
            $suspicious[] = 'Rapid session creation detected';
        }

        if (!empty($suspicious)) {
            SecurityLogger::logSuspiciousActivity(
                "Suspicious session activity detected: " . implode(', ', $suspicious),
                $user
            );
        }

        return $suspicious;
    }

    /**
     * Basic geographic region check (simplified)
     */
    private static function isDifferentGeographicRegion(string $ip1, string $ip2): bool
    {
        // This is a simplified check - in production, use GeoIP database
        $ip1Parts = explode('.', $ip1);
        $ip2Parts = explode('.', $ip2);
        
        // Different first octet likely means different region
        return $ip1Parts[0] !== $ip2Parts[0];
    }
}
