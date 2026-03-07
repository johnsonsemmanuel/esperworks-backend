<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use App\Mail\UsageLimitReachedMail;
use App\Models\Business;

class PlanLimitMiddleware
{
    /**
     * Check if the business is within its plan limits for a given resource.
     * Usage in routes: ->middleware('plan.limit:invoices') or ->middleware('plan.limit:businesses')
     *
     * This middleware only restricts actions (returns 403 when over limit). It never modifies,
     * clears, or overwrites user or business settings. All chosen settings (plan, branding,
     * preferences, etc.) are stored in the database and restored on login, so they are
     * retained even after the user logs out or when subscription limits block further usage.
     */
    public function handle(Request $request, Closure $next, string $resource = '')
    {
        $business = $request->route('business');
        $user = $request->user();

        // If no business-scoped route, we can still check account-wide limits (like 'businesses')
        if (!$business) {
            if ($resource === 'businesses' && $user) {
                $limit = $this->getAccountWideLimit($user, 'businesses');
                $currentUsage = \App\Models\Business::where('user_id', $user->id)->count();
                if ($limit !== -1 && $currentUsage >= $limit) {
                    return $this->limitExceededResponse('free', 'businesses', $limit, $currentUsage);
                }
            }
            return $next($request);
        }

        $limits = $business->getPlanLimits();
        $plan = $business->isOnTrial() ? 'pro' : ($business->plan ?? 'free');

        if (!$resource || !isset($limits[$resource])) {
            return $next($request);
        }

        $effectiveResource = $resource;
        if ($resource === 'contracts' && $request->input('type') === 'proposal' && isset($limits['proposals'])) {
            $effectiveResource = 'proposals';
        }

        $limit = $limits[$effectiveResource] ?? $limits[$resource];
        if ($limit === -1) {
            return $next($request); // Unlimited
        }

        // Boolean feature check (limit 0 means feature not available for this plan)
        // Except for things that are naturally 0 (like starting usage)
        $booleanFeatures = ['branding', 'client_portal', 'accounting_dashboard'];
        if (in_array($effectiveResource, $booleanFeatures) && $limit <= 0) {
            return $this->limitExceededResponse($plan, $effectiveResource, 0, 0);
        }

        $currentUsage = $this->getCurrentUsage($business, $effectiveResource);

        // Also check referral bonus features
        $bonusExtra = $this->getReferralBonus($user, $effectiveResource);
        $effectiveLimit = $limit + $bonusExtra;

        if ($currentUsage >= $effectiveLimit) {
            // Optionally email the business owner once per day about the limit for this resource
            $this->maybeEmailUsageLimit($business, $effectiveResource, $effectiveLimit, $currentUsage);
            return $this->limitExceededResponse($plan, $effectiveResource, $effectiveLimit, $currentUsage);
        }

        return $next($request);
    }

    private function getAccountWideLimit($user, string $resource): int
    {
        // For account-wide limits, we check the highest plan among all user's businesses
        // or just default to free if they have none.
        $highestBusiness = $user->businesses()->orderByRaw("CASE 
            WHEN plan = 'pro' THEN 3 
            WHEN plan = 'growth' THEN 2 
            ELSE 1 END DESC")->first();
        
        if ($highestBusiness && $highestBusiness->isOnTrial()) {
            $limits = \App\Models\Business::getPlanLimitsForPlan('pro');
        } else {
            $limits = \App\Models\Business::getPlanLimitsForPlan($highestBusiness->plan ?? 'free');
        }

        return $limits[$resource] ?? 1;
    }

    private function limitExceededResponse(string $plan, string $resource, $limit, $usage)
    {
        $planName = \App\Models\Business::planDisplayName($plan);
        $message = "You're operating at full capacity. Upgrade to keep workflows uninterrupted.";
        
        if (in_array($resource, ['branding', 'client_portal', 'accounting_dashboard'])) {
            $message = "This feature is not available on your current plan. Upgrade to unlock it.";
        }

        return response()->json([
            'message' => $message,
            'limit' => $limit,
            'usage' => $usage,
            'plan' => $plan,
            'plan_name' => $planName,
            'upgrade_required' => true,
        ], 403);
    }

    private function getCurrentUsage($business, string $resource): int|float
    {
        return match ($resource) {
            'invoices' => $business->invoices()->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->count(),
            'contracts' => $business->contracts()->where('type', 'contract')->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->count(),
            'proposals' => $business->contracts()->where('type', 'proposal')->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->count(),
            'businesses' => \App\Models\Business::where('user_id', $business->user_id)->count(),
            'users' => \App\Models\TeamMember::where('business_id', $business->id)->count() + 1,
            'storage_gb' => (float) $business->getStorageUsed(),
            'recurring_invoices' => $business->recurringInvoices()->where('is_active', true)->count(),
            'invoice_templates' => $business->invoiceTemplates()->count(),
            'clients' => $business->clients()->count(),
            'expenses' => $business->expenses()->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->count(),
            default => 0,
        };
    }

    private function getReferralBonus($user, string $resource): int
    {
        if (!$user) return 0;

        $bonusFeatures = $user->referral_bonus_features ?? [];
        if (!is_array($bonusFeatures)) return 0;

        return match ($resource) {
            'invoices' => in_array('extra_5_invoices', $bonusFeatures) ? 5 : 0,
            'businesses' => in_array('extra_business', $bonusFeatures) ? 1 : 0,
            default => 0,
        };
    }

    /**
     * Send a one-per-day email to the business owner when a usage limit is hit.
     */
    private function maybeEmailUsageLimit(Business $business, string $resource, int|float $limit, int|float $usage): void
    {
        try {
            $owner = $business->owner;
            if (!$owner || !$owner->email) {
                return;
            }
            $cacheKey = sprintf('usage_limit_mail:%d:%s:%s', $business->id, $resource, now()->toDateString());
            if (Cache::has($cacheKey)) {
                return;
            }

            Mail::to($owner->email)->queue(new UsageLimitReachedMail($business, $resource, (int) $limit, (int) $usage));
            Cache::put($cacheKey, true, now()->endOfDay());
        } catch (\Throwable $e) {
            // Fail silently – limit response to API is still returned
        }
    }
}
