<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Business;

class BusinessOwnerMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Authentication required.'], 401);
        }

        $businessParam = $request->route('business');
        $business = $businessParam instanceof Business
            ? $businessParam
            : (is_numeric($businessParam) ? Business::find($businessParam) : null);
        if (!$business) {
            if (!$user->isBusinessOwner()) {
                return response()->json(['message' => 'Unauthorized. Business owner access required.'], 403);
            }
            return $next($request);
        }

        // Ensure the resolved Business model is set on the route for downstream controllers
        $request->route()->setParameter('business', $business);

        $isOwner = (int) $business->user_id === (int) $user->id;
        if ($isOwner) {
            return $next($request);
        }

        $teamRole = $user->teamRoleForBusiness((int) $business->id);
        if (!$teamRole) {
            return response()->json(['message' => 'Business not found.'], 404);
        }

        if (!$this->isTeamRoleAllowed($request, $teamRole)) {
            return response()->json([
                'message' => 'You do not have permission for this action.',
                'team_role' => $teamRole,
            ], 403);
        }

        $request->attributes->set('team_role', $teamRole);

        return $next($request);
    }

    private function isTeamRoleAllowed(Request $request, string $teamRole): bool
    {
        if ($teamRole === 'admin') {
            return true;
        }

        $method = strtoupper($request->method());
        $path = '/' . ltrim((string) $request->path(), '/');

        if ($teamRole === 'viewer') {
            return $method === 'GET';
        }

        $businessParam = $request->route('business');
        $businessId = $businessParam instanceof Business ? $businessParam->id : $businessParam;
        $restrictedPrefixes = [
            '/api/businesses/' . $businessId . '/team',
        ];

        if ($teamRole === 'staff') {
            // Staff can manage day-to-day docs/clients but not billing, plan, payment setup, or team access.
            if ($method !== 'GET') {
                if (str_contains($path, '/upgrade') || str_contains($path, '/billing-history') || str_contains($path, '/payment-setup')) {
                    return false;
                }
                foreach ($restrictedPrefixes as $prefix) {
                    if (str_starts_with($path, $prefix)) {
                        return false;
                    }
                }
            }
            return true;
        }

        if ($teamRole === 'accountant') {
            // Accountants focus on invoices/payments/expenses/accounting; no team/admin settings.
            if ($method !== 'GET') {
                if (str_contains($path, '/team') || str_contains($path, '/branding') || str_contains($path, '/signature') || str_contains($path, '/upgrade')) {
                    return false;
                }
                if (str_contains($path, '/contracts') || str_contains($path, '/clients')) {
                    return false;
                }
            }
            return true;
        }

        return false;
    }
}
