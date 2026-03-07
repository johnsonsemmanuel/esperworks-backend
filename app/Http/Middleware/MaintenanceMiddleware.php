<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Setting;

class MaintenanceMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if maintenance mode is enabled via centralized settings
        $maintenanceMode = (bool) (Setting::get('maintenance_mode', false) ?? false);

        // Allow admin users to access during maintenance
        if ($maintenanceMode) {
            $user = $request->user();
            
            // Check if user is authenticated and is admin
            if (!$user || !$user->isAdmin()) {
                // For API requests, return JSON response
                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => 'Platform is currently under maintenance. Please try again later.',
                        'maintenance_mode' => true,
                    ], 503);
                }
                
                // For web requests, you could return a maintenance view
                return response()->view('errors.maintenance', [], 503);
            }
        }

        return $next($request);
    }
}
