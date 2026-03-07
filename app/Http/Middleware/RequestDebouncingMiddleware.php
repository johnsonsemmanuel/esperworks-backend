<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RequestDebouncingMiddleware
{
    /**
     * Default debounce time in seconds
     */
    const DEFAULT_DEBOUNCE_TIME = 2; // 2 seconds

    /**
     * Routes that should be debounced
     */
    const DEBOUNCED_ROUTES = [
        'invoices.*.send',
        'invoices.*.sign',
        'contracts.*.sign',
        'payments.*.initiate',
        'payments.*.verify',
        'clients.*.invite',
        'business.*.update',
    ];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, int $seconds = self::DEFAULT_DEBOUNCE_TIME)
    {
        // Only debounce specific HTTP methods
        if (!in_array($request->method(), ['POST', 'PUT', 'PATCH'])) {
            return $next($request);
        }

        // Get the route name
        $routeName = $request->route()?->getName();
        if (!$routeName) {
            return $next($request);
        }

        // Check if this route should be debounced
        if (!$this->shouldDebounce($routeName)) {
            return $next($request);
        }

        // Generate debounce key
        $key = $this->generateDebounceKey($request, $routeName);

        // Check if request is already being processed
        if ($this->isRequestInProgress($key)) {
            return $this->debounceResponse($request, $key);
        }

        // Mark request as in progress
        $this->markRequestInProgress($key, $seconds);

        try {
            $response = $next($request);

            // Clear debounce key on successful response
            $this->clearRequestInProgress($key);

            return $response;
        } catch (\Exception $e) {
            // Clear debounce key on exception
            $this->clearRequestInProgress($key);
            
            throw $e;
        }
    }

    /**
     * Check if a route should be debounced
     */
    private function shouldDebounce(string $routeName): bool
    {
        foreach (self::DEBOUNCED_ROUTES as $pattern) {
            if ($this->matchesPattern($routeName, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if route name matches a pattern
     */
    private function matchesPattern(string $routeName, string $pattern): bool
    {
        // Convert wildcard pattern to regex
        $regex = str_replace('*', '.*', preg_quote($pattern, '/'));
        
        return preg_match("/^{$regex}$/", $routeName);
    }

    /**
     * Generate a debounce key for the request
     */
    private function generateDebounceKey(Request $request, string $routeName): string
    {
        $userId = auth()->id();
        $businessId = $request->route('business');
        $clientId = $request->route('client');
        $invoiceId = $request->route('invoice');
        $contractId = $request->route('contract');

        // Create a unique key based on user and relevant resource IDs
        $keyParts = [
            'debounce',
            $routeName,
            $userId,
        ];

        // Add relevant resource IDs
        if ($businessId) $keyParts[] = "biz:{$businessId}";
        if ($clientId) $keyParts[] = "client:{$clientId}";
        if ($invoiceId) $keyParts[] = "invoice:{$invoiceId}";
        if ($contractId) $keyParts[] = "contract:{$contractId}";

        // Add request fingerprint for additional uniqueness
        $fingerprint = $this->generateRequestFingerprint($request);
        $keyParts[] = $fingerprint;

        return implode(':', $keyParts);
    }

    /**
     * Generate a fingerprint of the request for debouncing
     */
    private function generateRequestFingerprint(Request $request): string
    {
        // Include relevant request data for fingerprinting
        $data = [
            'method' => $request->method(),
            'path' => $request->path(),
            'ip' => $request->ip(),
        ];

        // Include specific request parameters based on route
        $routeName = $request->route()?->getName();
        if ($routeName) {
            switch (true) {
                case str_contains($routeName, 'invoices.send'):
                    $data['invoice_id'] = $request->route('invoice');
                    break;
                case str_contains($routeName, 'payments.initiate'):
                    $data['invoice_id'] = $request->route('invoice');
                    $data['amount'] = $request->input('amount');
                    break;
                case str_contains($routeName, 'business.update'):
                    $data['business_id'] = $request->route('business');
                    break;
            }
        }

        return hash('sha256', json_encode($data));
    }

    /**
     * Check if a request is already in progress
     */
    private function isRequestInProgress(string $key): bool
    {
        return Cache::has($key);
    }

    /**
     * Mark a request as in progress
     */
    private function markRequestInProgress(string $key, int $seconds): void
    {
        Cache::put($key, [
            'started_at' => now()->toDateTimeString(),
            'user_id' => auth()->id(),
            'ip' => request()->ip(),
        ], $seconds);

        Log::info('Request debounce activated', [
            'key' => $key,
            'seconds' => $seconds,
            'user_id' => auth()->id(),
            'ip' => request()->ip(),
        ]);
    }

    /**
     * Clear request in progress marker
     */
    private function clearRequestInProgress(string $key): void
    {
        Cache::forget($key);
    }

    /**
     * Return a debounce response
     */
    private function debounceResponse(Request $request, string $key)
    {
        $cacheData = Cache::get($key, []);
        $startedAt = $cacheData['started_at'] ?? now()->toDateTimeString();

        Log::warning('Request blocked by debounce', [
            'key' => $key,
            'started_at' => $startedAt,
            'user_id' => auth()->id(),
            'ip' => request()->ip(),
            'route' => $request->route()?->getName(),
        ]);

        return response()->json([
            'message' => 'Request already in progress. Please wait a moment before trying again.',
            'error' => 'DEBOUNCE_ACTIVE',
            'retry_after' => 2,
            'started_at' => $startedAt,
        ], 429);
    }

    /**
     * Get debounce statistics
     */
    public static function getStats(): array
    {
        $keys = Cache::getRedis()?->keys('debounce:*') ?? [];
        $activeRequests = [];

        foreach ($keys as $key) {
            $data = Cache::get($key);
            if ($data) {
                $activeRequests[] = [
                    'key' => $key,
                    'started_at' => $data['started_at'],
                    'user_id' => $data['user_id'],
                    'ip' => $data['ip'],
                ];
            }
        }

        return [
            'active_requests' => count($activeRequests),
            'debounced_routes' => self::DEBOUNCED_ROUTES,
            'default_debounce_time' => self::DEFAULT_DEBOUNCE_TIME,
            'active_requests_details' => $activeRequests,
        ];
    }

    /**
     * Clear all debounce keys (for testing/maintenance)
     */
    public static function clearAll(): int
    {
        $keys = Cache::getRedis()?->keys('debounce:*') ?? [];
        $cleared = 0;

        foreach ($keys as $key) {
            if (Cache::forget($key)) {
                $cleared++;
            }
        }

        Log::info('All debounce keys cleared', [
            'cleared_count' => $cleared,
        ]);

        return $cleared;
    }
}
