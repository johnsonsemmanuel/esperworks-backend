<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\JsonResponse;

Route::get('/health', function (): JsonResponse {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toISOString(),
        'environment' => config('app.env'),
        'version' => '1.0.0',
        'services' => [
            'database' => checkDatabase(),
            'cache' => checkCache(),
            'queue' => checkQueue(),
        ]
    ]);
});

function checkDatabase(): string
{
    try {
        \DB::connection()->getPdo();
        return 'connected';
    } catch (\Exception $e) {
        return 'error: ' . $e->getMessage();
    }
}

function checkCache(): string
{
    try {
        \Cache::put('health_check', 'ok', 10);
        $value = \Cache::get('health_check');
        return $value === 'ok' ? 'connected' : 'error';
    } catch (\Exception $e) {
        return 'error: ' . $e->getMessage();
    }
}

function checkQueue(): string
{
    try {
        $size = \Queue::size();
        return 'connected (jobs: ' . $size . ')';
    } catch (\Exception $e) {
        return 'error: ' . $e->getMessage();
    }
}
