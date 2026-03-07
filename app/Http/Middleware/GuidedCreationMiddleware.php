<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use App\Models\Business;
use Closure;
use Illuminate\Http\Request;

class GuidedCreationMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $business = $request->route('business');
        if (!$business instanceof Business) {
            return $next($request);
        }

        $plan = $business->plan ?? 'free';
        $planName = Business::planDisplayName($plan);

        if ($plan === 'free') {
            return response()->json([
                'message' => 'Guided creation is available on higher plans.',
                'plan' => $plan,
                'plan_name' => $planName,
                'upgrade_required' => true,
            ], 403);
        }

        if ($plan === 'starter') {
            $monthStart = now()->startOfMonth();
            $monthEnd = now()->endOfMonth();

            $startedCount = ActivityLog::query()
                ->where('business_id', $business->id)
                ->where('action', 'assistant.guided.started')
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->count();

            $limit = 5;
            if ($startedCount >= $limit) {
                return response()->json([
                    'message' => 'Guided creation is available on higher plans.',
                    'plan' => $plan,
                    'plan_name' => $planName,
                    'limit' => $limit,
                    'usage' => $startedCount,
                    'upgrade_required' => true,
                ], 403);
            }
        }

        return $next($request);
    }
}
