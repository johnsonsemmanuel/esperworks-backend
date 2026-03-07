<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckUserStatus
{
    /**
     * Reject requests from suspended users and revoke their token.
     * Without this, a user suspended after login retains access until token expiry.
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user && $user->status === 'suspended') {
            // Revoke the current token if using Sanctum token auth
            if ($user->currentAccessToken() && method_exists($user->currentAccessToken(), 'delete')) {
                $user->currentAccessToken()->delete();
            }

            return response()->json([
                'message' => 'Your account has been suspended. Please contact support.',
            ], 403);
        }

        return $next($request);
    }
}
