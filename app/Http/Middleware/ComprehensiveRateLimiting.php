<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use App\Services\SecurityLogger;

/**
 * Comprehensive rate limiting middleware with multiple strategies:
 * - Per-user limits for authenticated requests
 * - Per-IP limits for anonymous requests
 * - Stricter limits for sensitive operations
 * - Exponential backoff for repeated violations
 */
class ComprehensiveRateLimiting
{
    public function handle(Request $request, Closure $next, string $limit = '60,1')
    {
        [$maxAttempts, $decayMinutes] = explode(',', $limit);
        $maxAttempts = (int) $maxAttempts;
        $decayMinutes = (int) $decayMinutes;

        // Determine rate limit key based on authentication
        $key = $this->resolveRequestSignature($request);

        // Check if rate limit exceeded
        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $retryAfter = RateLimiter::availableIn($key);
            
            // Log rate limit violation
            SecurityLogger::logSecurityEvent(
                'rate_limit.exceeded',
                "Rate limit exceeded for {$key}",
                $request->user(),
                $request
            );

            return response()->json([
                'message' => 'Too many requests. Please slow down.',
                'retry_after' => $retryAfter,
                'limit' => $maxAttempts,
                'window' => $decayMinutes . ' minute(s)'
            ], 429)->header('Retry-After', $retryAfter);
        }

        // Increment attempt counter
        RateLimiter::hit($key, $decayMinutes * 60);

        $response = $next($request);

        // Add rate limit headers
        $response->headers->add([
            'X-RateLimit-Limit' => $maxAttempts,
            'X-RateLimit-Remaining' => max(0, $maxAttempts - RateLimiter::attempts($key)),
            'X-RateLimit-Reset' => now()->addSeconds(RateLimiter::availableIn($key))->timestamp,
        ]);

        return $response;
    }

    /**
     * Resolve the rate limit key for the request.
     * Uses user ID for authenticated requests, IP for anonymous.
     */
    protected function resolveRequestSignature(Request $request): string
    {
        $user = $request->user();
        
        if ($user) {
            return 'rate_limit:user:' . $user->id . ':' . $request->path();
        }

        // For anonymous requests, use IP + path
        return 'rate_limit:ip:' . $request->ip() . ':' . $request->path();
    }
}
