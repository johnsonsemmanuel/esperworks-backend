<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class IdempotencyService
{
    /**
     * Default idempotency key expiration time (24 hours)
     */
    const DEFAULT_EXPIRY = 86400; // 24 hours in seconds

    /**
     * Generate an idempotency key for a request
     */
    public static function generateKey(string $userId, string $action, array $context = []): string
    {
        $contextString = json_encode($context);
        $baseString = "{$userId}:{$action}:{$contextString}";
        
        return 'idempotency:' . hash('sha256', $baseString);
    }

    /**
     * Check if a request with the given idempotency key has already been processed
     */
    public static function isProcessed(string $key): bool
    {
        return Cache::has($key);
    }

    /**
     * Get the result of a previously processed request
     */
    public static function getResult(string $key): ?array
    {
        $cached = Cache::get($key);
        
        if ($cached && isset($cached['result'])) {
            return $cached['result'];
        }
        
        return null;
    }

    /**
     * Mark a request as processed with its result
     */
    public static function markProcessed(string $key, array $result, int $expiry = self::DEFAULT_EXPIRY): void
    {
        $data = [
            'result' => $result,
            'processed_at' => now()->toDateTimeString(),
            'key' => $key,
        ];

        Cache::put($key, $data, $expiry);
        
        Log::info('Idempotency key marked as processed', [
            'key' => $key,
            'processed_at' => $data['processed_at'],
        ]);
    }

    /**
     * Create an idempotency key for payment operations
     */
    public static function createPaymentKey(string $userId, string $invoiceId, float $amount): string
    {
        return self::generateKey($userId, 'payment', [
            'invoice_id' => $invoiceId,
            'amount' => $amount,
        ]);
    }

    /**
     * Create an idempotency key for invoice operations
     */
    public static function createInvoiceKey(string $userId, string $action, array $invoiceData): string
    {
        return self::generateKey($userId, "invoice_{$action}", $invoiceData);
    }

    /**
     * Create an idempotency key for expense operations
     */
    public static function createExpenseKey(string $userId, string $action, array $expenseData): string
    {
        return self::generateKey($userId, "expense_{$action}", $expenseData);
    }

    /**
     * Create an idempotency key from request headers
     */
    public static function createKeyFromRequest($request, string $userId, string $action): ?string
    {
        $idempotencyKey = $request->header('Idempotency-Key');
        
        if (!$idempotencyKey) {
            return null;
        }

        // Validate key format
        if (!is_string($idempotencyKey) || strlen($idempotencyKey) > 255) {
            Log::warning('Invalid idempotency key format', [
                'key' => $idempotencyKey,
                'user_id' => $userId,
                'action' => $action,
            ]);
            return null;
        }

        return "idempotency:{$userId}:{$action}:{$idempotencyKey}";
    }

    /**
     * Clean up expired idempotency keys (for maintenance jobs)
     */
    public static function cleanupExpired(): int
    {
        // This would typically be handled by Cache TTL expiration
        // But we can implement manual cleanup if needed
        Log::info('Idempotency cleanup completed');
        return 0;
    }

    /**
     * Get statistics about idempotency usage
     */
    public static function getStats(): array
    {
        // This would require cache inspection capabilities
        // For now, return basic info
        return [
            'enabled' => true,
            'default_expiry' => self::DEFAULT_EXPIRY,
            'cache_driver' => config('cache.default'),
        ];
    }

    /**
     * Validate idempotency key from request
     */
    public static function validateKey($request): ?string
    {
        $key = $request->header('Idempotency-Key');
        
        if (!$key) {
            return null;
        }

        // Basic validation
        if (!is_string($key) || strlen($key) < 8 || strlen($key) > 255) {
            return null;
        }

        // Check for potentially dangerous characters
        if (preg_match('/[<>"\']/', $key)) {
            return null;
        }

        return $key;
    }

    /**
     * Handle idempotency for a request
     */
    public static function handleIdempotency($request, string $userId, string $action, callable $callback): array
    {
        $idempotencyKey = self::createKeyFromRequest($request, $userId, $action);
        
        // If no idempotency key provided, proceed normally
        if (!$idempotencyKey) {
            return $callback();
        }

        // Check if request was already processed
        if (self::isProcessed($idempotencyKey)) {
            $result = self::getResult($idempotencyKey);
            
            if ($result) {
                Log::info('Returning cached result for idempotent request', [
                    'key' => $idempotencyKey,
                    'user_id' => $userId,
                    'action' => $action,
                ]);
                
                return $result;
            }
        }

        // Process the request
        $result = $callback();
        
        // Cache the result
        self::markProcessed($idempotencyKey, $result);
        
        return $result;
    }
}
