<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\JsonResponse;

Route::get('/health', function (): JsonResponse {
    // Database check
    $databaseStatus = 'error';
    try {
        \DB::connection()->getPdo();
        $databaseStatus = 'connected';
    } catch (\Exception $e) {
        $databaseStatus = 'error: ' . $e->getMessage();
    }

    // Cache check
    $cacheStatus = 'error';
    try {
        \Cache::put('health_check', 'ok', 10);
        $value = \Cache::get('health_check');
        $cacheStatus = $value === 'ok' ? 'connected' : 'error';
    } catch (\Exception $e) {
        $cacheStatus = 'error: ' . $e->getMessage();
    }

    // Queue check
    $queueStatus = 'error';
    try {
        $size = \Queue::size();
        $queueStatus = 'connected (jobs: ' . $size . ')';
    } catch (\Exception $e) {
        $queueStatus = 'error: ' . $e->getMessage();
    }

    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toISOString(),
        'environment' => config('app.env'),
        'version' => '1.0.0',
        'services' => [
            'database' => $databaseStatus,
            'cache' => $cacheStatus,
            'queue' => $queueStatus,
        ]
    ]);
});
