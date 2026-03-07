<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AdminPermissionMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $permission)
    {
        $user = $request->user();
        
        if (!$user || !$user->isAdmin()) {
            return response()->json(['message' => 'Unauthorized. Admin access required.'], 403);
        }

        if (!$user->hasAdminPermission($permission)) {
            return response()->json([
                'message' => 'Insufficient permissions for this action',
                'required_permission' => $permission,
                'admin_role' => $user->getAdminRole()
            ], 403);
        }

        // Log admin action for audit
        \App\Services\SecurityLogger::logSecurityEvent(
            'admin.action',
            "Admin performed action requiring permission: {$permission}",
            $user,
            $request
        );

        return $next($request);
    }
}
