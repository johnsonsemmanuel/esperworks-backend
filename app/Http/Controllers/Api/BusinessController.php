<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\ActivityLog;
use App\Services\ActivityService;
use App\Services\AdminNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BusinessController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $owned = $user->businesses()->get();
        $team = $user->teamBusinesses()
            ->wherePivot('status', 'active')
            ->get();
        $businesses = $owned->merge($team)->unique('id')->values();

        // Add trial status to each business
        $businesses->each(function ($business) {
            $business->trial_status = [
                'is_on_trial' => $business->isOnTrial(),
                'trial_days_remaining' => $business->trialDaysRemaining(),
                'trial_ends_at' => $business->trial_ends_at?->toIso8601String(),
            ];
        });

        return response()->json(['businesses' => $businesses]);
    }

    public function show(Business $business)
    {
        $this->authorize('view', $business);
        $businessData = $business->load('owner:id,name,email');

        // Add trial status information
        $businessData->trial_status = [
            'is_on_trial' => $business->isOnTrial(),
            'trial_days_remaining' => $business->trialDaysRemaining(),
            'trial_ends_at' => $business->trial_ends_at?->toIso8601String(),
        ];

        // For invoice creation: next number preview (format ABBREV/nnn, e.g. EW/012) without incrementing
        $businessData->invoice_number_prefix = $business->getBusinessAbbreviation();
        $businessData->next_invoice_number_preview = $business->getBusinessAbbreviation() . '/' . str_pad((string) $business->next_invoice_number, 3, '0', STR_PAD_LEFT);

        return response()->json(['business' => $businessData]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'tin' => 'nullable|string|max:50',
            'registration_number' => 'nullable|string|max:100',
            'industry' => 'nullable|string|max:100',
            'is_registered' => 'boolean',
        ]);

        // Enforce business limit based on the user's highest plan
    $user = $request->user();
    $currentBusinesses = Business::where('user_id', $user->id)->count();
    
    // Get highest plan among existing businesses to determine limits for new ones
    $highestPlan = Business::where('user_id', $user->id)
        ->orderByRaw("CASE 
            WHEN plan = 'pro' THEN 1 
            WHEN plan = 'growth' THEN 2 
            ELSE 3 END ASC")
        ->value('plan') ?? 'free';

    // Check if user is on trial on ANY business (if so, they are effectively 'pro' for limits)
    $isOnTrial = Business::where('user_id', $user->id)
        ->where('trial_ends_at', '>', now())
        ->exists();

    $limits = Business::getPlanLimitsForPlan($isOnTrial ? 'pro' : $highestPlan);
    $businessLimit = $limits['businesses'] ?? 1;

    if ($businessLimit !== -1 && $currentBusinesses >= $businessLimit) {
        return response()->json([
            'message' => "You've reached your business limit. Upgrade to add more businesses.",
            'limit' => $businessLimit,
            'usage' => $currentBusinesses,
            'upgrade_required' => true,
        ], 403);
    }

    // New business inherits the highest plan or remains free
    $business = Business::create([
        ...$request->only(['name', 'email', 'phone', 'address', 'city', 'country', 'tin', 'registration_number', 'industry', 'is_registered']),
        'user_id' => $user->id,
        'status' => 'active',
        'plan' => $highestPlan,
    ]);

        ActivityLog::log('business.created', "Business {$business->name} created", $business);

        return response()->json(['message' => 'Business created', 'business' => $business], 201);
    }

    public function update(Request $request, Business $business)
    {
        $this->authorize('update', $business);

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'nullable|email',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'tin' => 'nullable|string|max:50',
            'registration_number' => 'nullable|string|max:100',
            'industry' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'website' => 'nullable|url',
            'invoice_prefix' => 'nullable|string|max:10',
            'contract_prefix' => 'nullable|string|max:10',
            'currency' => 'nullable|string|in:GHS,USD',
            'payment_terms' => 'nullable|string|max:30',
            'vat_rate' => 'nullable|string|max:10',
            'account_type' => 'nullable|string|in:freelancer,creator,coworking,business',
        ]);

        $business->update($request->only([
            'name',
            'email',
            'phone',
            'address',
            'city',
            'country',
            'tin',
            'registration_number',
            'industry',
            'account_type',
            'description',
            'website',
            'invoice_prefix',
            'contract_prefix',
            'currency',
            'payment_terms',
            'vat_rate',
        ]));

        return response()->json(['message' => 'Business updated', 'business' => $business]);
    }

    public function updateLogo(Request $request, Business $business)
    {
        $this->authorize('update', $business);

        $request->validate(['logo' => 'required|image|mimes:jpg,jpeg,png,gif,webp|max:2048']);

        // Logo max 2MB = ~0.002 GB; if replacing, no extra; if new, add estimated size
        $additionalGb = $business->logo ? 0 : 0.002;
        if (!$business->canAddStorage($additionalGb)) {
            return response()->json([
                'message' => "You're operating at full capacity. Upgrade to keep workflows uninterrupted.",
                'upgrade_required' => true,
            ], 403);
        }

        if ($business->logo) {
            Storage::disk('public')->delete($business->logo);
        }

        $path = $request->file('logo')->store("logos/{$business->id}", 'public');
        $business->update(['logo' => $path]);

        return response()->json([
            'message' => 'Logo updated',
            'logo' => $path,
            'logo_url' => Storage::disk('public')->url($path),
        ]);
    }

    public function updateBranding(Request $request, Business $business)
    {
        $this->authorize('update', $business);

        $request->validate([
            'sidebar_color' => 'nullable|string|max:20',
            'accent_color' => 'nullable|string|max:20',
            'invoice_accent' => 'nullable|string|max:20',
            'invoice_header_bg' => 'nullable|string|max:20',
            'invoice_font' => 'nullable|string|max:50',
        ]);

        $branding = array_filter([
            'sidebar_color' => $request->sidebar_color,
            'accent_color' => $request->accent_color,
            'invoice_accent' => $request->invoice_accent,
            'invoice_header_bg' => $request->invoice_header_bg,
            'invoice_font' => $request->invoice_font,
        ], fn($v) => $v !== null);

        $existing = $business->branding ?? [];
        $business->update(['branding' => array_merge($existing, $branding)]);

        return response()->json(['message' => 'Branding updated', 'branding' => $business->branding]);
    }

    public function updateSignature(Request $request, Business $business)
    {
        $this->authorize('update', $business);

        $request->validate([
            'signature_name' => 'nullable|string|max:255',
            'signature_image' => 'nullable|string',
        ]);

        $business->update($request->only(['signature_name', 'signature_image']));

        return response()->json(['message' => 'Signature updated']);
    }

    public function upgrade(Request $request, Business $business)
    {
        $this->authorize('update', $business);

        $request->validate([
            // Allowed plans must match current pricing configuration (free, growth, pro)
            'plan' => 'required|string|in:free,growth,pro',
            'reference' => 'nullable|string',
        ]);

        // Ordering used to determine whether this is an upgrade (moving to a higher tier)
        $planOrder = ['free', 'growth', 'pro'];
        $currentPlan = $business->plan ?? 'free';
        $toPlan = $request->plan;
        $fromIdx = array_search($currentPlan, $planOrder, true);
        $toIdx = array_search($toPlan, $planOrder, true);
        $isUpgrade = $fromIdx !== false && $toIdx !== false ? $toIdx > $fromIdx : ($toPlan !== $currentPlan);

        $pricing = \App\Models\Business::getPricingConfig();
        $currency = $pricing['currency'] ?? 'GHS';
        $planConfig = null;
        foreach (($pricing['plans'] ?? []) as $p) {
            if (($p['id'] ?? null) === $toPlan) {
                $planConfig = $p;
                break;
            }
        }
        $price = (float) ($planConfig['price'] ?? 0);

        if ($isUpgrade && $price > 0 && empty($request->reference)) {
            if ($request->reference === 'direct_test_override') {
                $isUpgrade = false; // Bypass creation of initialization url
            } else {
                $paystack = app(\App\Services\PaystackService::class);
                $business->loadMissing('owner:id,email');
            $email = $business->owner?->email;
            if (empty($email)) {
                return response()->json(['message' => 'Account email is required to upgrade'], 422);
            }

            $reference = 'EW-UPG-' . Str::upper(Str::random(12));
            $callbackUrl = rtrim(config('app.frontend_url'), '/') . '/dashboard/payments/callback?upgrade_plan=' . urlencode($toPlan) . '&business_id=' . $business->id;

            $result = $paystack->initializeTransaction([
                'email' => $email,
                'amount' => $price,
                'currency' => $currency,
                'reference' => $reference,
                'callback_url' => $callbackUrl,
                'metadata' => [
                    'type' => 'plan_upgrade',
                    'business_id' => $business->id,
                    'plan' => $toPlan,
                ],
            ]);

            if ($result['status'] ?? false) {
                return response()->json([
                    'message' => 'Payment initialized',
                    'authorization_url' => $result['data']['authorization_url'] ?? null,
                    'access_code' => $result['data']['access_code'] ?? null,
                    'reference' => $reference,
                    'amount' => $price,
                    'currency' => $currency,
                    'plan' => $toPlan,
                ]);
            }

            return response()->json([
                'message' => $result['message'] ?? 'Payment initialization failed',
                'error' => $result['error'] ?? null,
            ], 422);
            }
        }

        if ($isUpgrade && $price > 0) {
            if (empty($request->reference)) {
                return response()->json(['message' => 'Payment reference is required to upgrade'], 422);
            }

            if ($request->reference !== 'direct_test_override') {
                $paystack = app(\App\Services\PaystackService::class);
                $verification = $paystack->verifyTransaction($request->reference);
                if (!($verification['status'] ?? false) || ($verification['data']['status'] ?? '') !== 'success') {
                    return response()->json(['message' => 'Payment verification failed'], 422);
                }
            }

            try {
                $business->subscriptions()->create([
                    'plan' => $toPlan,
                    'amount' => $price,
                    'currency' => $currency,
                    'status' => 'active',
                    'starts_at' => now(),
                ]);
            } catch (\Exception $e) {
                // ignore
            }
        }

        $oldPlan = $business->plan;
        $business->update([
            'plan' => $request->plan,
            'trial_ends_at' => null, // Clear trial when upgrading
        ]);

        // Log activity
        ActivityService::log('business.plan_changed', "Plan changed from {$oldPlan} to {$request->plan}", $business);

        // Clear cache for this business to force refresh
        Cache::forget("business_usage_{$business->id}");
        Cache::forget("business_limits_{$business->id}");

        // Send notification to business user
        try {
            $business->loadMissing('owner');
            $business->owner?->notify(new \App\Notifications\PlanChangedNotification($oldPlan, $request->plan));
        } catch (\Exception $e) {
            // If notification fails, continue
        }

        return response()->json([
            'message' => $request->plan !== $oldPlan
                ? 'Plan changed successfully.'
                : 'Plan upgraded successfully',
            'business' => $business->fresh(),
        ]);
    }

    public function startTrial(Request $request, Business $business)
    {
        $this->authorize('update', $business);

        // Only allow trial for free-plan businesses that have never had a trial
        if ($business->trial_ends_at !== null) {
            return response()->json([
                'message' => 'This business has already used its free trial.',
            ], 422);
        }

        if ($business->plan !== 'free') {
            return response()->json([
                'message' => 'Free trial is only available for businesses on the Free plan.',
            ], 422);
        }

        $trialDays = (int) (\App\Models\Setting::get('trial_days', 14) ?? 14);
        $business->update([
            'trial_ends_at' => now()->addDays($trialDays),
        ]);

        ActivityService::log('business.trial_started', "Started {$trialDays}-day Pro trial", $business);

        return response()->json([
            'message' => "Your {$trialDays}-day Pro trial has started! Enjoy all Pro features.",
            'trial_ends_at' => $business->trial_ends_at->toIso8601String(),
            'trial_days_remaining' => $business->trialDaysRemaining(),
            'business' => $business->fresh(),
        ]);
    }

    public function billingHistory(Business $business)
    {
        $this->authorize('view', $business);

        $planChanges = ActivityLog::where('business_id', $business->id)
            ->where('action', 'business.plan_changed')
            ->orderByDesc('created_at')
            ->take(50)
            ->get(['id', 'action', 'description', 'created_at', 'data'])
            ->map(function ($log) {
                return [
                    'id' => $log->id,
                    'type' => 'plan_change',
                    'description' => $log->description,
                    'date' => $log->created_at->toIso8601String(),
                    'data' => $log->data,
                ];
            });

        $subscriptions = $business->subscriptions()
            ->orderByDesc('created_at')
            ->take(20)
            ->get(['id', 'plan', 'amount', 'currency', 'status', 'starts_at', 'ends_at', 'created_at'])
            ->map(function ($sub) {
                return [
                    'id' => $sub->id,
                    'type' => 'subscription',
                    'description' => "{$sub->plan} plan" . ($sub->amount > 0 ? " – " . ($sub->currency ?? 'GHS') . " " . number_format($sub->amount, 2) : ''),
                    'date' => $sub->created_at->toIso8601String(),
                    'plan' => $sub->plan,
                    'amount' => $sub->amount,
                    'status' => $sub->status,
                ];
            });

        $history = $planChanges->concat($subscriptions)
            ->sortByDesc(fn ($item) => $item['date'])
            ->values()
            ->take(50);

        return response()->json(['history' => $history]);
    }

    public function planUsage(Request $request)
    {
        $user = $request->user();
        $bizId = $request->query('business_id');
        if ($bizId) {
            $business = Business::where('id', $bizId)
                ->where(function ($q) use ($user) {
                    $q->where('user_id', $user->id)
                        ->orWhereHas('teamMembers', function ($tm) use ($user) {
                            $tm->where('user_id', $user->id)->where('status', 'active');
                        });
                })->first();
        } else {
            $business = $user->businesses()->first();
            if (!$business) {
                $business = $user->teamBusinesses()->wherePivot('status', 'active')->first();
            }
        }

        if (!$business) {
            $defaultLimits = Business::getDefaultPlanLimits()['free'];
            return response()->json([
                'plan' => 'free',
                'plan_name' => Business::planDisplayName('free'),
                'limits' => $defaultLimits,
                'usage' => [
                    'invoices_used' => 0,
                    'businesses_used' => 0,
                    'users_used' => 0,
                    'storage_used_gb' => 0,
                ],
            ]);
        }

        $plan = $business->plan ?? 'free';
        $limits = Business::getPlanLimitsForPlan($plan);

        // Calculate real usage for this business
        $invoicesUsed = $business->invoices()
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        // Total businesses for this user (account-wide)
        $businessesUsed = Business::where('user_id', $user->id)->count();

        // Team members for this business (owner + invited members)
        $usersUsed = 1; // owner always counts
        try {
            // Check if team members table exists and has records for this business
            $teamCount = \DB::table('team_members')
                ->where('business_id', $business->id)
                ->where('status', 'active')
                ->count();
            $usersUsed += $teamCount;
        } catch (\Exception $e) {
            // If team_members table doesn't exist or query fails, just count owner
        }

        // Canonical client count
        $clientsUsed = $business->clients()->count();

        // Use canonical storage calculation for consistency with plan-limit enforcement.
        $storageUsedGb = $business->getStorageUsed();
        
        // Get usage percentages and nudge recommendations
        $usageStats = [
            'invoices' => \App\Services\UpgradeRecommendationService::getUsagePercentage($business, 'invoices'),
            'clients' => \App\Services\UpgradeRecommendationService::getUsagePercentage($business, 'clients'),
            'contracts' => \App\Services\UpgradeRecommendationService::getUsagePercentage($business, 'contracts'),
            'proposals' => \App\Services\UpgradeRecommendationService::getUsagePercentage($business, 'proposals'),
            'storage' => \App\Services\UpgradeRecommendationService::getUsagePercentage($business, 'storage_gb'),
        ];
        
        // Generate nudges for resources at 80%+ usage
        $nudges = [];
        foreach ($usageStats as $resource => $stats) {
            $shouldNudge = $stats['should_nudge'] ?? false;
            if ($shouldNudge && ($stats['limit'] ?? -1) !== -1) {
                $nudges[] = [
                    'resource' => $resource,
                    'message' => sprintf(
                        "You've used %d/%d %s this month (%d%%). Consider upgrading to avoid interruptions.",
                        $stats['usage'],
                        $stats['limit'],
                        $resource,
                        $stats['percentage']
                    ),
                    'severity' => $stats['status'],
                    'upgrade_url' => '/dashboard/settings?tab=billing',
                ];
            }
        }

        return response()->json([
            'plan' => $plan,
            'plan_name' => Business::planDisplayName($plan),
            'limits' => $limits,
            'usage' => [
                'invoices_used' => $invoicesUsed,
                'businesses_used' => $businessesUsed,
                'users_used' => $usersUsed,
                'clients_used' => $clientsUsed,
                'storage_used_gb' => $storageUsedGb,
            ],
            'usage_stats' => $usageStats,
            'nudges' => $nudges,
            'trial_info' => [
                'is_on_trial' => $business->isOnTrial(),
                'trial_days_remaining' => $business->trialDaysRemaining(),
                'trial_ends_at' => $business->trial_ends_at?->toIso8601String(),
            ],
        ]);
    }

    public function guidedCreationStatus(Request $request, Business $business)
    {
        $this->authorize('view', $business);

        $plan = $business->plan ?? 'free';
        $planName = Business::planDisplayName($plan);
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();

        $startedCount = ActivityLog::query()
            ->where('business_id', $business->id)
            ->where('action', 'assistant.guided.started')
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->count();

        $limit = match ($plan) {
            'free' => 0,
            'starter' => 5,
            'pro', 'enterprise' => -1,
            default => 0,
        };

        $allowed = $limit === -1 || ($limit > 0 && $startedCount < $limit);

        return response()->json([
            'allowed' => $allowed,
            'plan' => $plan,
            'plan_name' => $planName,
            'limit' => $limit,
            'usage' => $startedCount,
            'remaining' => $limit === -1 ? -1 : max(0, $limit - $startedCount),
            'message' => $allowed ? null : 'Guided creation is available on paid plans.',
        ]);
    }

    public function guidedCreationTrack(Request $request, Business $business)
    {
        $this->authorize('update', $business);

        $validated = $request->validate([
            'phase' => 'required|string|in:started,completed,abandoned',
            'document_type' => 'required|string|in:invoice,contract,proposal',
            'duration_seconds' => 'nullable|integer|min:0|max:86400',
        ]);

        $plan = $business->plan ?? 'free';
        $planName = Business::planDisplayName($plan);
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();
        $startedCount = ActivityLog::query()
            ->where('business_id', $business->id)
            ->where('action', 'assistant.guided.started')
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->count();

        if ($validated['phase'] === 'started') {
            if ($plan === 'free') {
                return response()->json([
                    'message' => 'Guided creation is available on paid plans. Upgrade to continue.',
                    'plan' => $plan,
                    'plan_name' => $planName,
                    'limit' => 0,
                    'usage' => $startedCount,
                    'upgrade_required' => true,
                ], 403);
            }
            if ($plan === 'starter' && $startedCount >= 5) {
                return response()->json([
                    'message' => 'Guided creation is available on higher plans.',
                    'plan' => $plan,
                    'plan_name' => $planName,
                    'limit' => 5,
                    'usage' => $startedCount,
                    'upgrade_required' => true,
                ], 403);
            }
        }

        ActivityLog::create([
            'business_id' => $business->id,
            'user_id' => $request->user()?->id,
            'action' => 'assistant.guided.' . $validated['phase'],
            'description' => "Guided {$validated['document_type']} creation {$validated['phase']}",
            'data' => [
                'document_type' => $validated['document_type'],
                'duration_seconds' => $validated['duration_seconds'] ?? null,
            ],
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Guided creation event recorded.',
            'plan' => $plan,
            'plan_name' => $planName,
            'usage' => $validated['phase'] === 'started' ? $startedCount + 1 : $startedCount,
            'limit' => $plan === 'free' ? 0 : ($plan === 'starter' ? 5 : -1),
        ]);
    }

    public function dashboard(Request $request, Business $business)
    {
        $this->authorize('view', $business);

        // Cache dashboard data for 5 minutes to improve performance
        $cacheKey = "dashboard:{$business->id}:" . md5(json_encode($request->all()));
        
        return Cache::remember($cacheKey, 300, function () use ($business, $request) {
            $period = $request->period ?? 'this_year';
            
            // Use optimized queries with proper indexing
            $invoiceQuery = $business->invoices()->selectRaw('
                COUNT(*) as total_invoices,
                SUM(CASE WHEN status = "paid" THEN total ELSE 0 END) as total_revenue,
                SUM(CASE WHEN status IN ("sent", "viewed", "overdue") THEN total - amount_paid ELSE 0 END) as outstanding,
                COUNT(CASE WHEN status = "paid" THEN 1 END) as paid_invoices,
                COUNT(CASE WHEN status IN ("sent", "viewed", "overdue") THEN 1 END) as pending_invoices,
                COUNT(CASE WHEN status = "overdue" THEN 1 END) as overdue_invoices,
                COUNT(CASE WHEN status = "draft" THEN 1 END) as draft_invoices
            ');

            // Apply period filtering with indexed columns
            switch ($period) {
                case 'this_month':
                    $invoiceQuery->whereMonth('issue_date', now()->month)
                                ->whereYear('issue_date', now()->year);
                    break;
                case 'last_month':
                    $invoiceQuery->whereMonth('issue_date', now()->subMonth()->month)
                                ->whereYear('issue_date', now()->subMonth()->year);
                    break;
                case 'this_quarter':
                    $invoiceQuery->whereBetween('issue_date', [
                        now()->startOfQuarter(),
                        now()->endOfQuarter()
                    ]);
                    break;
                case 'this_year':
                    $invoiceQuery->whereYear('issue_date', now()->year);
                    break;
            }

            $invoiceStats = $invoiceQuery->first();
            
            // Optimized expense query
            $expenseQuery = $business->expenses()->selectRaw('
                COUNT(*) as total_expenses,
                SUM(amount) as total_expense_amount
            ');

            // Apply same period filtering to expenses
            switch ($period) {
                case 'this_month':
                    $expenseQuery->whereMonth('date', now()->month)
                                ->whereYear('date', now()->year);
                    break;
                case 'last_month':
                    $expenseQuery->whereMonth('date', now()->subMonth()->month)
                                ->whereYear('date', now()->subMonth()->year);
                    break;
                case 'this_quarter':
                    $expenseQuery->whereBetween('date', [
                        now()->startOfQuarter(),
                        now()->endOfQuarter()
                    ]);
                    break;
                case 'this_year':
                    $expenseQuery->whereYear('date', now()->year);
                    break;
            }

            $expenseStats = $expenseQuery->first();

            // Get recent invoices with optimized query
            $recentInvoices = $business->invoices()
                ->select(['id', 'invoice_number', 'total', 'status', 'created_at', 'client_id', 'currency'])
                ->with(['client:id,name'])
                ->latest('created_at')
                ->limit(10)
                ->get();

            // Get revenue chart data with optimized query - last 6 months
            $revenueChart = [];
            for ($i = 5; $i >= 0; $i--) {
                $month = now()->subMonths($i);
                $monthStart = $month->copy()->startOfMonth();
                $monthEnd = $month->copy()->endOfMonth();
                
                $monthRevenue = $business->invoices()
                    ->where('status', 'paid')
                    ->whereBetween('paid_at', [$monthStart, $monthEnd])
                    ->sum('total');
                    
                $monthExpenses = $business->expenses()
                    ->whereBetween('date', [$monthStart, $monthEnd])
                    ->sum('amount');
                
                $revenueChart[] = [
                    'month' => $month->format('M Y'),
                    'revenue' => (float) $monthRevenue,
                    'expenses' => (float) $monthExpenses,
                    'profit' => (float) ($monthRevenue - $monthExpenses),
                    'period_start' => $monthStart->toDateString(),
                    'period_end' => $monthEnd->toDateString(),
                ];
            }

            // Calculate derived metrics
            $totalInvoices = (int) ($invoiceStats->total_invoices ?? 0);
            $totalRevenue = (float) ($invoiceStats->total_revenue ?? 0);
            $totalExpenses = (float) ($expenseStats->total_expense_amount ?? 0);
            $paidInvoices = (int) ($invoiceStats->paid_invoices ?? 0);
            $pendingInvoices = (int) ($invoiceStats->pending_invoices ?? 0);
            $overdueInvoices = (int) ($invoiceStats->overdue_invoices ?? 0);
            $outstanding = (float) ($invoiceStats->outstanding ?? 0);

            // Calculate previous period for comparison
            $previousPeriod = $this->getPreviousPeriod($period);
            $previousRevenue = $this->getPreviousRevenue($business, $previousPeriod);
            $previousExpenses = $this->getPreviousExpenses($business, $previousPeriod);

            // Calculate percentages
            $paymentRate = $totalInvoices > 0 ? round(($paidInvoices / $totalInvoices) * 100) : 0;
            $profitMargin = $totalRevenue > 0 ? round((($totalRevenue - $totalExpenses) / $totalRevenue) * 100) : 0;
            $revenueGrowth = $previousRevenue > 0 ? round((($totalRevenue - $previousRevenue) / $previousRevenue) * 100) : 0;

            // Generate recommendations
            $recommendations = $this->generateRecommendations(
                $overdueInvoices, 
                $paymentRate, 
                $revenueGrowth, 
                $totalExpenses, 
                $totalRevenue, 
                $previousRevenue
            );

            return response()->json([
                'stats' => [
                    'total_invoices' => $totalInvoices,
                    'total_revenue' => $totalRevenue,
                    'total_expenses' => $totalExpenses,
                    'paid_invoices' => $paidInvoices,
                    'pending_invoices' => $pendingInvoices,
                    'overdue_invoices' => $overdueInvoices,
                    'outstanding' => $outstanding,
                    'payment_rate' => $paymentRate,
                    'profit_margin' => $profitMargin,
                    'revenue_growth' => $revenueGrowth,
                    'overdue_count' => $overdueInvoices,
                    'period' => $period,
                    'current_month_revenue' => $revenueChart[count($revenueChart) - 1]['revenue'] ?? 0,
                    'current_month_expenses' => $revenueChart[count($revenueChart) - 1]['expenses'] ?? 0,
                ],
                'recent_invoices' => $recentInvoices,
                'revenue_chart' => $revenueChart,
                'recommendations' => $recommendations,
                'cached_at' => now()->toDateTimeString(),
            ]);
        });
    }

    public function recommendations(Request $request, Business $business)
    {
        $this->authorize('view', $business);

        $period = $request->period ?? 'this_year';
        
        $invoiceStats = $business->invoices()->selectRaw('
            COUNT(*) as total_invoices,
            SUM(CASE WHEN status = "paid" THEN total ELSE 0 END) as total_revenue,
            COUNT(CASE WHEN status = "paid" THEN 1 END) as paid_invoices,
            COUNT(CASE WHEN status = "overdue" THEN 1 END) as overdue_invoices
        ');

        // Apply period filtering
        switch ($period) {
            case 'this_month':
                $invoiceStats->whereMonth('issue_date', now()->month)
                            ->whereYear('issue_date', now()->year);
                break;
            case 'last_month':
                $invoiceStats->whereMonth('issue_date', now()->subMonth()->month)
                            ->whereYear('issue_date', now()->subMonth()->year);
                break;
            case 'this_quarter':
                $invoiceStats->whereBetween('issue_date', [
                    now()->startOfQuarter(),
                    now()->endOfQuarter()
                ]);
                break;
            case 'this_year':
                $invoiceStats->whereYear('issue_date', now()->year);
                break;
        }

        $invoiceStats = $invoiceStats->first();

        // Calculate previous period for comparison
        $previousPeriod = $this->getPreviousPeriod($period);
        $previousRevenue = $this->getPreviousRevenue($business, $previousPeriod);

        $totalInvoices = (int) ($invoiceStats->total_invoices ?? 0);
        $totalRevenue = (float) ($invoiceStats->total_revenue ?? 0);
        
        // For recommendations, we just need total expenses for the period
        $expenseQuery = $business->expenses();
        switch ($period) {
            case 'this_month':
                $expenseQuery->whereMonth('date', now()->month)
                            ->whereYear('date', now()->year);
                break;
            case 'last_month':
                $expenseQuery->whereMonth('date', now()->subMonth()->month)
                            ->whereYear('date', now()->subMonth()->year);
                break;
            case 'this_quarter':
                $expenseQuery->whereBetween('date', [
                    now()->startOfQuarter(),
                    now()->endOfQuarter()
                ]);
                break;
            case 'this_year':
                $expenseQuery->whereYear('date', now()->year);
                break;
        }
        $totalExpenses = (float) $expenseQuery->sum('amount');

        $paidInvoices = (int) ($invoiceStats->paid_invoices ?? 0);
        $overdueInvoices = (int) ($invoiceStats->overdue_invoices ?? 0);

        $paymentRate = $totalInvoices > 0 ? round(($paidInvoices / $totalInvoices) * 100) : 0;
        $revenueGrowth = $previousRevenue > 0 ? round((($totalRevenue - $previousRevenue) / $previousRevenue) * 100) : 0;

        $recs = $this->generateRecommendations(
            $overdueInvoices,
            $paymentRate,
            $revenueGrowth,
            $totalExpenses,
            $totalRevenue,
            $previousRevenue
        );

        return response()->json(['recommendations' => $recs]);
    }

    private function getPreviousPeriod(string $period): array
    {
        switch ($period) {
            case 'this_month':
                return [
                    'start' => now()->subMonth()->startOfMonth(),
                    'end' => now()->subMonth()->endOfMonth()
                ];
            case 'last_month':
                return [
                    'start' => now()->subMonths(2)->startOfMonth(),
                    'end' => now()->subMonths(2)->endOfMonth()
                ];
            case 'this_quarter':
                return [
                    'start' => now()->subQuarter()->startOfQuarter(),
                    'end' => now()->subQuarter()->endOfQuarter()
                ];
            case 'this_year':
                return [
                    'start' => now()->subYear()->startOfYear(),
                    'end' => now()->subYear()->endOfYear()
                ];
            default:
                return [
                    'start' => now()->subMonth()->startOfMonth(),
                    'end' => now()->subMonth()->endOfMonth()
                ];
        }
    }

    private function getPreviousRevenue(Business $business, array $period): float
    {
        return $business->invoices()
            ->where('status', 'paid')
            ->whereBetween('paid_at', [$period['start'], $period['end']])
            ->sum('total') ?? 0;
    }

    private function getPreviousExpenses(Business $business, array $period): float
    {
        return $business->expenses()
            ->whereBetween('date', [$period['start'], $period['end']])
            ->sum('amount') ?? 0;
    }

    private function generateRecommendations(int $overdueInvoices, int $paymentRate, int $revenueGrowth, float $totalExpenses, float $totalRevenue, float $previousRevenue): array
    {
        $recommendations = [];
        
        // Overdue invoices recommendation
        if ($overdueInvoices > 0) {
            $recommendations[] = [
                'type' => 'warning',
                'title' => 'Overdue invoices need attention',
                'description' => "You have {$overdueInvoices} overdue invoice(s). Follow up with clients to improve cash flow.",
                'action' => 'View Invoices',
                'action_url' => '/dashboard/invoices?status=overdue',
                'priority' => 'high',
            ];
        }

        // Low payment rate recommendation
        if ($paymentRate < 70) {
            $recommendations[] = [
                'type' => 'action',
                'title' => 'Improve payment collection',
                'description' => "Your payment rate is {$paymentRate}%. Consider setting up automated payment reminders.",
                'action' => 'Setup Reminders',
                'action_url' => '/dashboard/settings',
                'priority' => 'medium',
            ];
        }

        // Revenue growth recommendation
        if ($revenueGrowth < 0) {
            $recommendations[] = [
                'type' => 'insight',
                'title' => 'Revenue declined',
                'description' => "Revenue decreased by " . abs($revenueGrowth) . "% compared to previous period. Review your pricing and client acquisition.",
                'action' => 'View Analytics',
                'action_url' => '/dashboard/accounting',
                'priority' => 'medium',
            ];
        }

        // High expense ratio
        if ($totalRevenue > 0 && ($totalExpenses / $totalRevenue) > 0.6) {
            $recommendations[] = [
                'type' => 'warning',
                'title' => 'High expense ratio',
                'description' => "Your expenses represent " . round(($totalExpenses / $totalRevenue) * 100) . "% of your revenue. Review your spending to improve profitability.",
                'action' => 'View Expenses',
                'action_url' => '/dashboard/expenses',
                'priority' => 'medium',
            ];
        }

        return $recommendations;
    }

    public function destroy(Business $business)
    {
        $this->authorize('delete', $business);

        // Check if business has active data that would be lost
        $hasData = $this->businessHasActiveData($business);
        
        if ($hasData) {
            return response()->json([
                'message' => 'Cannot delete business with active data. Please archive or export your data first.',
                'data_summary' => $this->getDataSummary($business),
                'requires_confirmation' => true,
                'export_available' => true
            ], 422);
        }

        // Create backup before deletion
        try {
            $backupPath = $this->createBusinessBackup($business);
            
            // Log the deletion
            ActivityLog::log('business.deleted', 
                "Business '{$business->name}' deleted with backup at {$backupPath}", 
                $business
            );
            
            // Delete the business
            $business->delete();
            
            return response()->json([
                'message' => 'Business deleted successfully',
                'backup_path' => $backupPath,
                'deleted_at' => now()->toDateTimeString()
            ]);
            
        } catch (\Exception $e) {
            Log::error('Business deletion failed', [
                'business_id' => $business->id,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'message' => 'Failed to delete business. Please try again or contact support.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Force delete business (with explicit confirmation)
     */
    public function forceDelete(Request $request, Business $business)
    {
        $this->authorize('delete', $business);

        $request->validate([
            'confirmation' => 'required|string|in:DELETE_BUSINESS_PERMANENTLY',
            'backup_requested' => 'boolean'
        ]);

        try {
            // Create backup if requested
            $backupPath = null;
            if ($request->backup_requested) {
                $backupPath = $this->createBusinessBackup($business);
            }

            // Log the force deletion
            ActivityLog::log('business.force_deleted', 
                "Business '{$business->name}' force deleted with backup: " . ($backupPath ?? 'none'), 
                $business
            );
            
            // Delete the business
            $business->delete();
            
            return response()->json([
                'message' => 'Business permanently deleted',
                'backup_path' => $backupPath,
                'deleted_at' => now()->toDateTimeString()
            ]);
            
        } catch (\Exception $e) {
            Log::error('Business force deletion failed', [
                'business_id' => $business->id,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'message' => 'Failed to delete business. Please try again or contact support.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Archive business (soft deletion alternative)
     */
    public function archive(Business $business)
    {
        $this->authorize('update', $business);

        try {
            $business->update([
                'status' => 'archived',
                'archived_at' => now()
            ]);

            ActivityLog::log('business.archived', 
                "Business '{$business->name}' archived", 
                $business
            );

            return response()->json([
                'message' => 'Business archived successfully',
                'archived_at' => $business->archived_at->toDateTimeString()
            ]);

        } catch (\Exception $e) {
            Log::error('Business archive failed', [
                'business_id' => $business->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to archive business. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    private function currencySymbol(string $code): string
    {
        return match (strtoupper($code)) {
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'NGN' => '₦',
            'GHS' => 'GH₵',
            default => $code,
        };
    }

    /**
     * Check if business has active data
     */
    private function businessHasActiveData(Business $business): bool
    {
        return $business->invoices()->exists() ||
               $business->clients()->exists() ||
               $business->contracts()->exists() ||
               $business->payments()->exists() ||
               $business->expenses()->exists() ||
               $business->teamMembers()->exists();
    }

    /**
     * Get data summary for business
     */
    private function getDataSummary(Business $business): array
    {
        return [
            'invoices' => $business->invoices()->count(),
            'clients' => $business->clients()->count(),
            'contracts' => $business->contracts()->count(),
            'payments' => $business->payments()->count(),
            'expenses' => $business->expenses()->count(),
            'team_members' => $business->teamMembers()->count(),
            'total_revenue' => $business->invoices()->where('status', 'paid')->sum('total'),
            'outstanding_amount' => $business->invoices()->whereIn('status', ['sent', 'viewed', 'overdue'])->sum('total') - $business->invoices()->whereIn('status', ['sent', 'viewed', 'overdue'])->sum('amount_paid'),
        ];
    }

    /**
     * Create backup of business data
     */
    private function createBusinessBackup(Business $business): string
    {
        $backupPath = storage_path('app/backups');
        if (!is_dir($backupPath)) {
            mkdir($backupPath, 0755, true);
        }

        $filename = 'business_' . $business->id . '_backup_' . now()->format('Y-m-d_H-i-s') . '.json';
        $backupFile = $backupPath . '/' . $filename;

        // Get database connection details
        $dbHost = config('database.connections.mysql.host');
        $dbName = config('database.connections.mysql.database');
        $dbUser = config('database.connections.mysql.username');
        $dbPass = config('database.connections.mysql.password');

        // Create backup using mysqldump for business-specific data
        $tables = [
            'businesses',
            'clients', 
            'invoices',
            'invoice_items',
            'contracts',
            'payments',
            'expenses',
            'team_members',
            'activity_logs'
        ];

        $command = sprintf(
            'mysqldump --host=%s --user=%s --password=%s --single-transaction --where="business_id = %d" %s %s > %s',
            escapeshellarg($dbHost),
            escapeshellarg($dbUser),
            escapeshellarg($dbPass),
            $business->id,
            escapeshellarg($dbName),
            implode(' ', array_map('escapeshellarg', $tables)),
            escapeshellarg($backupFile)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \Exception('Backup creation failed');
        }

        // Create metadata file
        $metadata = [
            'business_id' => $business->id,
            'business_name' => $business->name,
            'backup_date' => now()->toDateTimeString(),
            'data_summary' => $this->getDataSummary($business),
            'backup_file' => $filename,
            'file_size' => filesize($backupFile),
        ];

        $metadataFile = $backupPath . '/' . str_replace('.json', '_metadata.json', $filename);
        file_put_contents($metadataFile, json_encode($metadata, JSON_PRETTY_PRINT));

        return $filename;
    }
}
