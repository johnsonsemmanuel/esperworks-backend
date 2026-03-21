<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        channels: __DIR__ . '/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
            'admin.permission' => \App\Http\Middleware\AdminPermissionMiddleware::class,
            'admin.rate_limit' => \App\Http\Middleware\AdminRateLimitMiddleware::class,
            'business.owner' => \App\Http\Middleware\BusinessOwnerMiddleware::class,
            'plan.limit' => \App\Http\Middleware\PlanLimitMiddleware::class,
            'security.headers' => \App\Http\Middleware\SecurityHeaders::class,
            'maintenance' => \App\Http\Middleware\MaintenanceMiddleware::class,
            'check.status' => \App\Http\Middleware\CheckUserStatus::class,
            'validate.public.token' => \App\Http\Middleware\ValidatePublicToken::class,
            'rate.limit' => \App\Http\Middleware\ComprehensiveRateLimiting::class,
        ]);

        // Apply CORS to all API routes
        $middleware->group('api', [
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);

        // Apply security headers to all API routes (append, don't replace defaults)
        $middleware->appendToGroup('api', [
            \App\Http\Middleware\SecurityHeaders::class,
        ]);

        // Apply maintenance middleware to all API routes (append, don't replace defaults)
        $middleware->appendToGroup('api', [
            \App\Http\Middleware\MaintenanceMiddleware::class,
        ]);

        // Block suspended users on all API routes
        $middleware->appendToGroup('api', [
            \App\Http\Middleware\CheckUserStatus::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
