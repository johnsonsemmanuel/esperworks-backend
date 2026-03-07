<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CircuitBreakerService
{
    /**
     * Circuit breaker states
     */
    const STATE_CLOSED = 'closed';
    const STATE_OPEN = 'open';
    const STATE_HALF_OPEN = 'half_open';

    /**
     * Default circuit breaker configuration
     */
    const DEFAULT_CONFIG = [
        'failure_threshold' => 5,      // Number of failures before opening
        'success_threshold' => 3,      // Number of successes before closing
        'timeout' => 60,              // Seconds to wait before trying again (open state)
        'reset_timeout' => 300,       // Seconds to wait before resetting (half-open state)
    ];

    /**
     * Check if a circuit is open for a specific service
     */
    public static function isOpen(string $service, array $config = []): bool
    {
        $key = "circuit_breaker:{$service}";
        $state = Cache::get($key, self::STATE_CLOSED);
        
        return $state === self::STATE_OPEN;
    }

    /**
     * Execute a function with circuit breaker protection
     */
    public static function execute(string $service, callable $callback, array $config = [])
    {
        $key = "circuit_breaker:{$service}";
        $config = array_merge(self::DEFAULT_CONFIG, $config);
        
        // Check if circuit is open
        if (self::isOpen($service, $config)) {
            throw new \Exception("Circuit breaker is open for service: {$service}");
        }

        try {
            $result = $callback();
            
            // Record success
            self::recordSuccess($service, $config);
            
            return $result;
        } catch (\Exception $e) {
            // Record failure
            self::recordFailure($service, $config, $e);
            
            throw $e;
        }
    }

    /**
     * Record a successful operation
     */
    public static function recordSuccess(string $service, array $config = []): void
    {
        $key = "circuit_breaker:{$service}";
        $state = Cache::get($key, self::STATE_CLOSED);
        $failureCount = (int) Cache::get("{$key}:failures", 0);
        $successCount = (int) Cache::get("{$key}:successes", 0);

        switch ($state) {
            case self::STATE_CLOSED:
                // Reset failure count on success in closed state
                Cache::put("{$key}:failures", 0, $config['reset_timeout']);
                Cache::put("{$key}:successes", 0, $config['reset_timeout']);
                break;

            case self::STATE_HALF_OPEN:
                // Increment success count
                $successCount++;
                Cache::put("{$key}:successes", $successCount, $config['reset_timeout']);
                
                // Close circuit if success threshold reached
                if ($successCount >= $config['success_threshold']) {
                    self::closeCircuit($service);
                }
                break;
        }

        Log::info('Circuit breaker success recorded', [
            'service' => $service,
            'state' => $state,
            'failure_count' => $failureCount,
            'success_count' => $successCount,
        ]);
    }

    /**
     * Record a failed operation
     */
    public static function recordFailure(string $service, array $config = [], \Exception $exception = null): void
    {
        $key = "circuit_breaker:{$service}";
        $state = Cache::get($key, self::STATE_CLOSED);
        $failureCount = (int) Cache::get("{$key}:failures", 0);

        // Increment failure count
        $failureCount++;
        Cache::put("{$key}:failures", $failureCount, $config['reset_timeout']);

        // Check if we should open the circuit
        if ($state === self::STATE_CLOSED && $failureCount >= $config['failure_threshold']) {
            self::openCircuit($service, $config);
        } elseif ($state === self::STATE_HALF_OPEN) {
            // Open circuit again on failure in half-open state
            self::openCircuit($service, $config);
        }

        Log::warning('Circuit breaker failure recorded', [
            'service' => $service,
            'state' => $state,
            'failure_count' => $failureCount,
            'threshold' => $config['failure_threshold'],
            'error' => $exception ? $exception->getMessage() : null,
        ]);
    }

    /**
     * Open the circuit for a service
     */
    public static function openCircuit(string $service, array $config = []): void
    {
        $key = "circuit_breaker:{$service}";
        
        Cache::put($key, self::STATE_OPEN, $config['timeout']);
        Cache::put("{$key}:opened_at", now()->timestamp, $config['timeout']);
        
        Log::warning('Circuit breaker opened', [
            'service' => $service,
            'timeout' => $config['timeout'],
            'opened_at' => now()->toDateTimeString(),
        ]);
    }

    /**
     * Close the circuit for a service
     */
    public static function closeCircuit(string $service): void
    {
        $key = "circuit_breaker:{$service}";
        
        Cache::put($key, self::STATE_CLOSED, 3600); // Keep closed state for 1 hour
        Cache::forget("{$key}:failures");
        Cache::forget("{$key}:successes");
        Cache::forget("{$key}:opened_at");
        
        Log::info('Circuit breaker closed', [
            'service' => $service,
            'closed_at' => now()->toDateTimeString(),
        ]);
    }

    /**
     * Get circuit breaker status for a service
     */
    public static function getStatus(string $service): array
    {
        $key = "circuit_breaker:{$service}";
        $state = Cache::get($key, self::STATE_CLOSED);
        $failureCount = (int) Cache::get("{$key}:failures", 0);
        $successCount = (int) Cache::get("{$key}:successes", 0);
        $openedAt = Cache::get("{$key}:opened_at");

        return [
            'service' => $service,
            'state' => $state,
            'failure_count' => $failureCount,
            'success_count' => $successCount,
            'opened_at' => $openedAt ? Carbon::createFromTimestamp($openedAt)->toDateTimeString() : null,
            'is_open' => $state === self::STATE_OPEN,
            'is_closed' => $state === self::STATE_CLOSED,
            'is_half_open' => $state === self::STATE_HALF_OPEN,
        ];
    }

    /**
     * Execute a payment gateway call with circuit breaker
     */
    public static function executePaymentGateway(callable $callback, array $config = [])
    {
        return self::execute('payment_gateway', $callback, $config);
    }

    /**
     * Execute an email service call with circuit breaker
     */
    public static function executeEmailService(callable $callback, array $config = [])
    {
        return self::execute('email_service', $callback, $config);
    }

    /**
     * Execute an external API call with circuit breaker
     */
    public static function executeExternalApi(string $apiName, callable $callback, array $config = [])
    {
        return self::execute("external_api:{$apiName}", $callback, $config);
    }

    /**
     * Reset all circuit breakers (for testing/maintenance)
     */
    public static function resetAll(): int
    {
        $keys = Cache::getRedis()?->keys('circuit_breaker:*') ?? [];
        $resetCount = 0;

        foreach ($keys as $key) {
            if (Cache::forget($key)) {
                $resetCount++;
            }
        }

        Log::info('All circuit breakers reset', [
            'reset_count' => $resetCount,
        ]);

        return $resetCount;
    }

    /**
     * Get statistics for all circuit breakers
     */
    public static function getStats(): array
    {
        $keys = Cache::getRedis()?->keys('circuit_breaker:*') ?? [];
        $stats = [];

        foreach ($keys as $key) {
            $service = str_replace('circuit_breaker:', '', $key);
            $stats[$service] = self::getStatus($service);
        }

        return $stats;
    }

    /**
     * Check if a service is available (not in circuit breaker)
     */
    public static function isAvailable(string $service): bool
    {
        return !self::isOpen($service);
    }

    /**
     * Get fallback response for a failed service
     */
    public static function getFallbackResponse(string $service, array $defaultResponse = []): array
    {
        return [
            'success' => false,
            'message' => "Service '{$service}' is temporarily unavailable. Please try again later.",
            'service' => $service,
            'circuit_breaker' => self::getStatus($service),
            'fallback_used' => true,
            'timestamp' => now()->toDateTimeString(),
        ] + $defaultResponse;
    }

    /**
     * Execute with fallback (returns fallback response on failure)
     */
    public static function executeWithFallback(string $service, callable $callback, array $fallbackResponse = [], array $config = [])
    {
        try {
            return self::execute($service, $callback, $config);
        } catch (\Exception $e) {
            Log::error('Circuit breaker fallback triggered', [
                'service' => $service,
                'error' => $e->getMessage(),
            ]);

            return self::getFallbackResponse($service, $fallbackResponse);
        }
    }
}
