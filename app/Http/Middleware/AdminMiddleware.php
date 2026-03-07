<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        
        if (!$user || !$user->isAdmin()) {
            return response()->json(['message' => 'Unauthorized. Admin access required.'], 403);
        }

        // Add admin role and permissions to response headers for debugging
        $response = $next($request);
        $response->headers->set('X-Admin-Role', $user->getAdminRole());
        $response->headers->set('X-Admin-ID', $user->id);

        return $response;
    }
}
