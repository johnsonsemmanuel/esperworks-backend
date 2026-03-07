<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Cache;

class AdminRateLimitMiddleware
{
    /**
     * Rate limits for different admin actions
     */
    const RATE_LIMITS = [
        'destructive' => [
            'max_attempts' => 3,
            'decay_minutes' => 60, // 3 destructive actions per hour
        ],
        'critical' => [
            'max_attempts' => 10,
            'decay_minutes' => 60, // 10 critical actions per hour
        ],
        'normal' => [
            'max_attempts' => 100,
            'decay_minutes' => 60, // 100 normal actions per hour
        ],
        'read' => [
            'max_attempts' => 1000,
            'decay_minutes' => 60, // 1000 read actions per hour
        ],
    ];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $limitType = 'normal')
    {
        $user = $request->user();
        
        if (!$user || !$user->isAdmin()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $limitConfig = self::RATE_LIMITS[$limitType] ?? self::RATE_LIMITS['normal'];
        
        // Use user ID + IP as key for rate limiting
        $key = "admin_rate_limit:{$user->id}:{$request->ip()}:{$limitType}";
        
        // Check rate limit
        $attempts = Cache::get($key, 0);
        
        if ($attempts >= $limitConfig['max_attempts']) {
            // Log rate limit violation
            \App\Services\SecurityLogger::logSecurityEvent(
                'admin.rate_limit_exceeded',
                "Admin rate limit exceeded for {$limitType} actions",
                $user,
                $request
            );

            return response()->json([
                'message' => 'Too many admin actions. Please try again later.',
                'retry_after' => $limitConfig['decay_minutes'] * 60,
                'limit_type' => $limitType,
                'max_attempts' => $limitConfig['max_attempts']
            ], 429);
        }

        // Increment counter
        Cache::put($key, $attempts + 1, now()->addMinutes($limitConfig['decay_minutes']));

        // Add rate limit headers
        $response = $next($request);
        $response->headers->set('X-RateLimit-Limit', $limitConfig['max_attempts']);
        $response->headers->set('X-RateLimit-Remaining', max(0, $limitConfig['max_attempts'] - $attempts - 1));
        $response->headers->set('X-RateLimit-Reset', now()->addMinutes($limitConfig['decay_minutes'])->timestamp);

        return $response;
    }

    /**
     * Get rate limit status for admin user
     */
    public static function getRateLimitStatus(int $userId, string $ip): array
    {
        $status = [];
        
        foreach (self::RATE_LIMITS as $type => $config) {
            $key = "admin_rate_limit:{$userId}:{$ip}:{$type}";
            $attempts = Cache::get($key, 0);
            
            $status[$type] = [
                'attempts' => $attempts,
                'max_attempts' => $config['max_attempts'],
                'remaining' => max(0, $config['max_attempts'] - $attempts),
                'reset_at' => now()->addMinutes($config['decay_minutes'])->timestamp,
                'is_limited' => $attempts >= $config['max_attempts'],
            ];
        }
        
        return $status;
    }

    /**
     * Clear rate limits for admin user (for testing or admin reset)
     */
    public static function clearRateLimits(int $userId, string $ip): int
    {
        $cleared = 0;
        
        foreach (array_keys(self::RATE_LIMITS) as $type) {
            $key = "admin_rate_limit:{$userId}:{$ip}:{$type}";
            if (Cache::forget($key)) {
                $cleared++;
            }
        }
        
        return $cleared;
    }
}
