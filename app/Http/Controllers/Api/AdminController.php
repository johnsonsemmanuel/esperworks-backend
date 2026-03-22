<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Business;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Client;
use App\Models\ActivityLog;
use App\Models\LoginDevice;
use App\Services\ActivityService;
use App\Services\AdminNotificationService;
use App\Services\SecurityLogger;
use App\Models\Setting;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    /**
     * Check if the authenticated admin has the required permission.
     * Throws 403 if permission is denied.
     */
    protected function checkAdminPermission(Request $request, string $permission): void
    {
        $user = $request->user();
        
        if (!$user || !$user->isAdmin()) {
            abort(403, 'Admin access required');
        }

        if (!$user->hasAdminPermission($permission)) {
            SecurityLogger::logSecurityEvent(
                'admin.permission_denied',
                "Admin {$user->email} attempted {$permission} without permission",
                $user,
                $request
            );
            
            abort(403, "You don't have permission to perform this action. Required: {$permission}");
        }
    }

    public function dashboard(Request $request)
    {
        $this->checkAdminPermission($request, 'view_dashboard');
        $totalUsers = User::count();
        $totalBusinesses = Business::count();
        $activeBusinesses = Business::where('status', 'active')->count();
        $suspendedBusinesses = Business::where('status', 'suspended')->count();
        $totalInvoices = Invoice::count();
        $totalRevenue = Payment::where('status', 'success')->sum('amount');
        $totalClients = Client::count();
        $monthlyRevenue = Payment::where('status', 'success')
            ->whereMonth('paid_at', now()->month)->whereYear('paid_at', now()->year)->sum('amount');

        $planDistribution = Business::selectRaw('plan, COUNT(*) as count')
            ->groupBy('plan')->get()->pluck('count', 'plan');

        $start = now()->subMonths(11)->startOfMonth();
        $end = now()->endOfMonth();

        $revenueByMonth = Payment::where('status', 'success')
            ->whereBetween('paid_at', [$start, $end])
            ->selectRaw('YEAR(paid_at) as year, MONTH(paid_at) as month, SUM(amount) as total')
            ->groupBy('year', 'month')
            ->get()
            ->keyBy(fn ($row) => $row->year . '-' . $row->month);

        $businessesByMonth = Business::whereBetween('created_at', [$start, $end])
            ->selectRaw('YEAR(created_at) as year, MONTH(created_at) as month, COUNT(*) as total')
            ->groupBy('year', 'month')
            ->get()
            ->keyBy(fn ($row) => $row->year . '-' . $row->month);

        $usersByMonth = User::whereBetween('created_at', [$start, $end])
            ->selectRaw('YEAR(created_at) as year, MONTH(created_at) as month, COUNT(*) as total')
            ->groupBy('year', 'month')
            ->get()
            ->keyBy(fn ($row) => $row->year . '-' . $row->month);

        $revenueChart = [];
        for ($i = 11; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $key = $month->year . '-' . $month->month;
            $revenueChart[] = [
                'month' => $month->format('M Y'),
                'revenue' => (float) ($revenueByMonth[$key]->total ?? 0),
                'new_businesses' => (int) ($businessesByMonth[$key]->total ?? 0),
                'new_users' => (int) ($usersByMonth[$key]->total ?? 0),
            ];
        }

        $recentActivity = ActivityLog::with('user:id,name,email')
            ->latest()->take(20)->get();

        $recentBusinesses = Business::with('owner:id,name,email')
            ->latest()->take(10)->get();

        return response()->json([
            'stats' => [
                'total_users' => $totalUsers,
                'total_businesses' => $totalBusinesses,
                'active_businesses' => $activeBusinesses,
                'suspended_businesses' => $suspendedBusinesses,
                'total_invoices' => $totalInvoices,
                'total_revenue' => $totalRevenue,
                'monthly_revenue' => $monthlyRevenue,
                'total_clients' => $totalClients,
            ],
            'plan_distribution' => $planDistribution,
            'revenue_chart' => $revenueChart,
            'recent_activity' => $recentActivity,
            'recent_businesses' => $recentBusinesses,
        ]);
    }

    public function users(Request $request)
    {
        $query = User::query();

        if ($request->role) $query->where('role', $request->role);
        if ($request->status) $query->where('status', $request->status);
        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('email', 'like', "%{$request->search}%");
            });
        }

        $users = $query->latest()->get();
        
        // Calculate meta statistics
        $meta = [
            'total' => $users->count(),
            'admins' => $users->where('role', 'admin')->count(),
            'business_owners' => $users->where('role', 'business_owner')->count(),
            'clients' => $users->where('role', 'client')->count(),
            'suspended' => $users->where('status', 'suspended')->count(),
        ];

        return response()->json([
            'data' => $users,
            'meta' => $meta,
        ]);
    }

    public function showUser(User $user)
    {
        $user->load(['businesses', 'clientProfiles.business:id,name', 'loginDevices']);

        $recentActivity = ActivityLog::where('user_id', $user->id)
            ->with('business:id,name')->latest()->take(30)->get();

        return response()->json([
            'user' => $user,
            'devices' => $user->loginDevices()->latest('last_active_at')->get(),
            'recent_activity' => $recentActivity,
        ]);
    }

    public function suspendUser(User $user, Request $request)
    {
        $this->checkAdminPermission($request, 'suspend_users');
        
        if ($user->isAdmin()) {
            return response()->json(['message' => 'Cannot suspend admin users'], 422);
        }

        $auditData = [
            'user_id' => $user->id,
            'user_email' => $user->email,
            'user_name' => $user->name,
            'previous_status' => $user->status,
            'businesses_affected' => $user->businesses()->pluck(['id', 'name', 'status'])->toArray(),
            'active_invoices_count' => $user->businesses()->withCount('invoices')->sum('invoices_count'),
            'reason' => 'Admin suspension',
            'suspension_impact' => $this->assessUserSuspensionImpact($user)
        ];

        $user->update(['status' => 'suspended']);
        $user->businesses()->update(['status' => 'suspended']);
        $user->tokens()->delete();

        // Enhanced audit logging
        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'admin.user_suspended_detailed',
            'description' => "User {$user->name} ({$user->email}) suspended by admin",
            'model_type' => User::class,
            'model_id' => $user->id,
            'ip_address' => request()->ip(),
            'data' => $auditData,
        ]);

        // Security logging
        SecurityLogger::logSecurityEvent(
            'admin.user_suspended',
            "Admin suspended user: {$user->email}",
            $user,
            $request
        );

        return response()->json(['message' => "User {$user->name} has been suspended"]);
    }

    public function activateUser(User $user, Request $request)
    {
        $this->checkAdminPermission($request, 'activate_users');
        $auditData = [
            'user_id' => $user->id,
            'user_email' => $user->email,
            'user_name' => $user->name,
            'previous_status' => $user->status,
            'businesses_affected' => $user->businesses()->pluck(['id', 'name', 'status'])->toArray(),
            'reactivation_reason' => 'Admin reactivation'
        ];

        $user->update(['status' => 'active']);
        $user->businesses()->update(['status' => 'active']);

        // Enhanced audit logging
        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'admin.user_activated_detailed',
            'description' => "User {$user->name} ({$user->email}) reactivated by admin",
            'model_type' => User::class,
            'model_id' => $user->id,
            'ip_address' => request()->ip(),
            'data' => $auditData,
        ]);

        return response()->json(['message' => "User {$user->name} has been activated"]);
    }

    private function assessUserSuspensionImpact(User $user): array
    {
        $businesses = $user->businesses;
        $impact = [
            'businesses_count' => $businesses->count(),
            'total_invoices' => 0,
            'paid_invoices' => 0,
            'outstanding_invoices' => 0,
            'active_contracts' => 0,
            'total_clients' => 0,
        ];

        foreach ($businesses as $business) {
            $impact['total_invoices'] += $business->invoices()->count();
            $impact['paid_invoices'] += $business->invoices()->where('status', 'paid')->count();
            $impact['outstanding_invoices'] += $business->invoices()->whereIn('status', ['sent', 'viewed', 'overdue'])->count();
            $impact['active_contracts'] += $business->contracts()->where('status', 'active')->count();
            $impact['total_clients'] += $business->clients()->count();
        }

        return $impact;
    }

    public function businesses(Request $request)
    {
        $query = Business::with('owner:id,name,email');

        if ($request->plan) $query->where('plan', $request->plan);
        if ($request->status) $query->where('status', $request->status);
        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('email', 'like', "%{$request->search}%");
            });
        }

        $businesses = $query->latest()->get();
        
        // Calculate meta statistics
        $meta = [
            'total' => $businesses->count(),
            'active' => $businesses->where('status', 'active')->count(),
            'suspended' => $businesses->where('status', 'suspended')->count(),
            'total_revenue' => $businesses->sum(function($biz) {
                return $biz->invoices()->where('status', 'paid')->sum('total');
            }),
        ];

        return response()->json([
            'data' => $businesses,
            'meta' => $meta,
        ]);
    }

    public function showBusiness($id)
    {
        $business = Business::findOrFail($id);
        $business->load('owner:id,name,email');
        $business->loadCount(['invoices', 'clients', 'contracts', 'expenses', 'payments']);

        $stats = [
            'total_revenue' => $business->invoices()->where('status', 'paid')->sum('total'),
            'outstanding' => $business->invoices()->whereIn('status', ['sent', 'viewed', 'overdue'])
                ->selectRaw('SUM(total - amount_paid) as owed')->value('owed') ?? 0,
            'total_expenses' => $business->expenses()->sum('amount'),
        ];

        $recentActivity = ActivityLog::where('business_id', $business->id)
            ->with('user:id,name')->latest()->take(20)->get();

        return response()->json([
            'business' => $business,
            'stats' => $stats,
            'recent_activity' => $recentActivity,
        ]);
    }

    public function suspendBusiness($id)
    {
        $business = Business::findOrFail($id);
        $business->update(['status' => 'suspended']);

        ActivityService::logBusinessSuspended($business);
        AdminNotificationService::notifyBusinessSuspended($business);

        return response()->json(['message' => "Business {$business->name} suspended"]);
    }

    public function activateBusiness($id)
    {
        $business = Business::findOrFail($id);
        $business->update(['status' => 'active']);

        ActivityService::logBusinessActivated($business);
        AdminNotificationService::notifyBusinessActivated($business);

        return response()->json(['message' => "Business {$business->name} activated"]);
    }

    public function changePlan(Request $request, $id)
    {
        $business = Business::findOrFail($id);
        
        $request->validate(['plan' => 'required|in:free,starter,pro,enterprise']);

        $oldPlan = $business->plan;
        $newPlan = $request->plan;
        
        // Enhanced financial audit logging
        $financialData = [
            'old_plan' => $oldPlan,
            'new_plan' => $newPlan,
            'plan_value_difference' => $this->calculatePlanValueDifference($oldPlan, $newPlan),
            'business_id' => $business->id,
            'business_name' => $business->name,
            'owner_id' => $business->user_id,
            'owner_email' => $business->user->email,
            'subscription_impact' => $this->assessSubscriptionImpact($oldPlan, $newPlan, $business),
        ];

        $business->update(['plan' => $request->plan]);

        // Detailed financial audit log
        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'admin.plan_changed_financial',
            'description' => "Plan changed for {$business->name} (ID: {$business->id}) from {$oldPlan} to {$newPlan}",
            'model_type' => Business::class,
            'model_id' => $business->id,
            'ip_address' => request()->ip(),
            'data' => $financialData,
        ]);

        ActivityService::logPlanChanged($business, $oldPlan, $request->plan);
        
        if ($oldPlan !== $request->plan) {
            AdminNotificationService::notifyPlanUpgrade($business, $oldPlan, $request->plan);
            
            // Clear cache for this business to force refresh
            \Cache::forget("business_usage_{$business->id}");
            \Cache::forget("business_limits_{$business->id}");
            
            // Send notification to business user
            try {
                $business->user->notify(new \App\Notifications\PlanChangedNotification($oldPlan, $request->plan));
            } catch (\Exception $e) {
                // If notification fails, continue
            }
        }

        return response()->json(['message' => "Plan changed to {$request->plan}"]);
    }

    private function calculatePlanValueDifference(string $oldPlan, string $newPlan): array
    {
        $planValues = [
            'free' => 0,
            'starter' => 25,
            'pro' => 49,
            'enterprise' => 149,
        ];

        $oldValue = $planValues[$oldPlan] ?? 0;
        $newValue = $planValues[$newPlan] ?? 0;
        $difference = $newValue - $oldValue;

        return [
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'monthly_difference' => $difference,
            'annual_difference' => $difference * 12,
            'impact_type' => $difference > 0 ? 'increase' : ($difference < 0 ? 'decrease' : 'no_change')
        ];
    }

    private function assessSubscriptionImpact(string $oldPlan, string $newPlan, Business $business): array
    {
        $impact = [
            'revenue_impact' => 'unknown',
            'feature_changes' => [],
            'billing_changes' => [],
            'user_notification_required' => true,
        ];

        // Assess revenue impact
        $valueDiff = $this->calculatePlanValueDifference($oldPlan, $newPlan);
        if ($valueDiff['monthly_difference'] > 0) {
            $impact['revenue_impact'] = 'positive';
        } elseif ($valueDiff['monthly_difference'] < 0) {
            $impact['revenue_impact'] = 'negative';
        } else {
            $impact['revenue_impact'] = 'neutral';
        }

        // Assess feature changes
        $oldLimits = $this->getPlanLimits($oldPlan);
        $newLimits = $this->getPlanLimits($newPlan);
        
        foreach ($newLimits as $feature => $newLimit) {
            $oldLimit = $oldLimits[$feature] ?? 0;
            if ($newLimit > $oldLimit) {
                $impact['feature_changes'][] = [
                    'feature' => $feature,
                    'change' => 'increased',
                    'from' => $oldLimit,
                    'to' => $newLimit
                ];
            } elseif ($newLimit < $oldLimit) {
                $impact['feature_changes'][] = [
                    'feature' => $feature,
                    'change' => 'decreased',
                    'from' => $oldLimit,
                    'to' => $newLimit
                ];
            }
        }

        return $impact;
    }

    private function getPlanLimits(string $plan): array
    {
        $limits = [
            'free' => ['invoices' => 5, 'contracts' => 1, 'clients' => 10, 'businesses' => 1, 'team_members' => 1],
            'starter' => ['invoices' => 50, 'contracts' => 5, 'clients' => 50, 'businesses' => 2, 'team_members' => 2],
            'pro' => ['invoices' => -1, 'contracts' => -1, 'clients' => -1, 'businesses' => 5, 'team_members' => 5],
            'enterprise' => ['invoices' => -1, 'contracts' => -1, 'clients' => -1, 'businesses' => -1, 'team_members' => -1],
        ];

        return $limits[$plan] ?? [];
    }

    public function activityLogs(Request $request)
    {
        $query = ActivityLog::with(['user:id,name,email', 'business:id,name']);

        if ($request->business_id) $query->where('business_id', $request->business_id);
        if ($request->user_id) $query->where('user_id', $request->user_id);
        if ($request->action) $query->where('action', 'like', "%{$request->action}%");

        return response()->json($query->latest()->paginate($request->per_page ?? 30));
    }

    public function getPricing(Request $request)
    {
        // Use Business::getPricingConfig() for single source of truth
        $pricing = Business::getPricingConfig();

        return response()->json($pricing);
    }

    public function updatePricing(Request $request)
    {
        $request->validate([
            'plans' => 'required|array',
            'plans.*.id' => 'required|string',
            'plans.*.name' => 'required|string',
            'plans.*.price' => 'required|numeric|min:0',
            'plans.*.annual_price' => 'sometimes|numeric|min:0',
            'plans.*.tagline' => 'sometimes|string|nullable',
            'plans.*.isHighlighted' => 'sometimes|boolean',
            'plans.*.period' => 'sometimes|string',
            'plans.*.features' => 'required|array',
            'plans.*.limits' => 'sometimes|array',
            'currency' => 'sometimes|string',
            'currency_symbol' => 'sometimes|string',
        ]);

        $defaultLimits = \App\Models\Business::getDefaultPlanLimits();
        $existingPricing = Setting::get('pricing');
        $existingPlansById = [];
        foreach ($existingPricing['plans'] ?? [] as $p) {
            if (isset($p['id'])) {
                $existingPlansById[$p['id']] = $p;
            }
        }

        $plans = [];
        foreach ($request->plans as $plan) {
            $id = $plan['id'];
            $limits = $plan['limits'] ?? $existingPlansById[$id]['limits'] ?? $defaultLimits[$id] ?? $defaultLimits['free'];
            
            $plans[] = [
                'id' => $id,
                'name' => $plan['name'],
                'price' => $plan['price'],
                'annual_price' => $plan['annual_price'] ?? ($plan['price'] * 12 * 0.8),
                'tagline' => $plan['tagline'] ?? '',
                'isHighlighted' => (bool) ($plan['isHighlighted'] ?? false),
                'period' => $plan['period'] ?? 'month',
                'features' => $plan['features'],
                'limits' => $limits,
            ];
        }

        $pricing = [
            'plans' => $plans,
            'currency' => $request->currency ?? 'GHS',
            'currency_symbol' => $request->currency_symbol ?? 'GH₵',
        ];

        Setting::set('pricing', $pricing);
        \App\Models\Business::clearPricingCache();

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'admin.pricing_updated',
            'description' => 'Platform pricing plans updated',
            'ip_address' => request()->ip(),
        ]);

        return response()->json(['message' => 'Pricing updated successfully', 'pricing' => $pricing]);
    }

    public function getSettings()
    {
        $defaults = [
            'platform_name' => 'EsperWorks',
            'platform_url' => '',
            'support_email' => config('mail.from.address', 'support@esperworks.com'),
            'default_currency' => 'GHS',
            'maintenance_mode' => false,
            'trial_days' => 14,
            'allow_free_plan' => true,
            'max_businesses_free' => 1,
            'max_businesses_starter' => 2,
            'max_businesses_pro' => 5,
            'session_lifetime_minutes' => (int) config('session.lifetime', 120),
            'password_min_length' => 8,
            'require_2fa_for_admin' => false,
            'mail_from_address' => config('mail.from.address', ''),
            'mail_from_name' => config('mail.from.name', 'EsperWorks'),
            'notification_defaults' => [
                'invoice_paid' => true,
                'invoice_viewed' => true,
                'payment_overdue' => true,
                'new_client' => false,
                'weekly_summary' => true,
                'monthly_report' => true,
            ],
        ];

        $storedNotificationDefaults = Setting::get('notification_defaults');
        if (is_array($storedNotificationDefaults)) {
            $defaults['notification_defaults'] = array_merge($defaults['notification_defaults'], $storedNotificationDefaults);
        }

        $settings = [
            'platform_name' => Setting::get('platform_name', $defaults['platform_name']),
            'platform_url' => Setting::get('platform_url', $defaults['platform_url']),
            'support_email' => Setting::get('support_email', $defaults['support_email']),
            'default_currency' => Setting::get('default_currency', $defaults['default_currency']),
            'maintenance_mode' => (bool) Setting::get('maintenance_mode', $defaults['maintenance_mode']),
            'trial_days' => (int) Setting::get('trial_days', $defaults['trial_days']),
            'allow_free_plan' => (bool) Setting::get('allow_free_plan', $defaults['allow_free_plan']),
            'max_businesses_free' => (int) Setting::get('max_businesses_free', $defaults['max_businesses_free']),
            'max_businesses_starter' => (int) Setting::get('max_businesses_starter', $defaults['max_businesses_starter']),
            'max_businesses_pro' => (int) Setting::get('max_businesses_pro', $defaults['max_businesses_pro']),
            'session_lifetime_minutes' => (int) Setting::get('session_lifetime_minutes', $defaults['session_lifetime_minutes']),
            'password_min_length' => (int) Setting::get('password_min_length', $defaults['password_min_length']),
            'require_2fa_for_admin' => (bool) Setting::get('require_2fa_for_admin', $defaults['require_2fa_for_admin']),
            'mail_from_address' => Setting::get('mail_from_address', $defaults['mail_from_address']),
            'mail_from_name' => Setting::get('mail_from_name', $defaults['mail_from_name']),
            'notification_defaults' => $defaults['notification_defaults'],
        ];

        return response()->json($settings);
    }

    public function updateSettings(Request $request)
    {
        $allowed = [
            'platform_name', 'platform_url', 'support_email', 'default_currency',
            'maintenance_mode', 'trial_days', 'allow_free_plan',
            'max_businesses_free', 'max_businesses_starter', 'max_businesses_pro',
            'session_lifetime_minutes', 'password_min_length', 'require_2fa_for_admin',
            'mail_from_address', 'mail_from_name', 'notification_defaults',
        ];

        $data = $request->only($allowed);

        foreach ($data as $key => $value) {
            if ($key === 'notification_defaults') {
                if (is_array($value)) {
                    Setting::set($key, $value);
                }
                continue;
            }
            if ($value !== null && $value !== '') {
                if (in_array($key, ['maintenance_mode', 'allow_free_plan', 'require_2fa_for_admin'], true)) {
                    Setting::set($key, (bool) $value);
                } elseif (in_array($key, ['trial_days', 'max_businesses_free', 'max_businesses_starter', 'max_businesses_pro', 'session_lifetime_minutes', 'password_min_length'], true)) {
                    Setting::set($key, (int) $value);
                } else {
                    Setting::set($key, $value);
                }
            }
        }

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'admin.settings_updated',
            'description' => 'Platform settings updated',
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'Settings updated']);
    }

    public function clearData(Request $request)
    {
        $request->validate([
            'confirm' => 'required|string',
            'verification_code' => 'required|string'
        ]);

        // Step 1: Basic confirmation check
        if ($request->confirm !== 'DELETE ALL DATA') {
            return response()->json(['message' => 'Invalid confirmation'], 422);
        }

        // Step 2: Generate and verify time-sensitive code
        $sessionKey = 'admin_data_clear_verification';
        $storedData = session($sessionKey);
        
        if (!$storedData) {
            // Generate verification code and store with expiry
            $verificationCode = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $expiresAt = now()->addMinutes(5);
            
            session([$sessionKey => [
                'code' => $verificationCode,
                'expires_at' => $expiresAt,
                'initiated_at' => now(),
                'admin_id' => auth()->id()
            ]]);
            
            return response()->json([
                'message' => 'Verification code required',
                'requires_verification' => true,
                'expires_at' => $expiresAt->toISOString(),
                'hint' => 'Check your admin email for the verification code'
            ], 422);
        }
        
        // Step 3: Verify code and expiry
        if (now()->isAfter($storedData['expires_at'])) {
            session()->forget($sessionKey);
            return response()->json(['message' => 'Verification code expired. Please try again.'], 422);
        }
        
        if ($request->verification_code !== $storedData['code']) {
            return response()->json(['message' => 'Invalid verification code'], 422);
        }
        
        // Step 4: Additional safety checks
        if ($storedData['admin_id'] !== auth()->id()) {
            return response()->json(['message' => 'Session mismatch. Please restart the process.'], 422);
        }
        
        if (now()->diffInMinutes($storedData['initiated_at']) < 2) {
            return response()->json(['message' => 'Please wait at least 2 minutes before proceeding.'], 422);
        }

        // Step 5: Final confirmation with detailed warning
        $stats = [
            'users' => User::where('role', '!=', 'admin')->count(),
            'businesses' => Business::count(),
            'invoices' => Invoice::count(),
            'payments' => Payment::count(),
            'clients' => Client::count(),
        ];

        // Security log before destructive action
        \App\Services\SecurityLogger::logSecurityEvent(
            'admin.clear_data_initiated',
            'Admin initiated full data clear with all safeguards passed',
            $request->user(),
            $request
        );

        // Clear the session
        session()->forget($sessionKey);

        return response()->json([
            'message' => 'FINAL CONFIRMATION REQUIRED',
            'requires_final_confirmation' => true,
            'warning' => 'This will permanently delete ALL platform data except admin accounts:',
            'stats' => $stats,
            'final_confirmation_required' => 'DELETE EVERYTHING PERMANENTLY'
        ], 422);
    }

    public function executeDataClear(Request $request)
    {
        $request->validate(['final_confirmation' => 'required|string']);

        if ($request->final_confirmation !== 'DELETE EVERYTHING PERMANENTLY') {
            return response()->json(['message' => 'Invalid final confirmation'], 422);
        }

        // Final security check - require admin 2FA if enabled
        $user = $request->user();
        if ($user->two_factor_enabled) {
            return response()->json([
                'message' => 'Admin 2FA must be disabled before performing this action',
                'requires_2fa_disable' => true
            ], 422);
        }

        // Execute the data clear operation
        \DB::transaction(function () {
            \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();

            \App\Models\ActivityLog::truncate();
            \App\Models\Payment::truncate();
            \App\Models\Invoice::query()->delete();
            \App\Models\Contract::truncate();
            \App\Models\Expense::truncate();
            \App\Models\Client::truncate();
            \App\Models\Business::truncate();

            // Keep admin users, delete others
            User::where('role', '!=', 'admin')->delete();

            // Clear personal access tokens for non-admin
            \DB::table('personal_access_tokens')->whereNotIn('tokenable_id', User::where('role', 'admin')->pluck('id'))->delete();

            \Illuminate\Support\Facades\Schema::enableForeignKeyConstraints();
        });

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'admin.data_cleared',
            'description' => 'All platform data cleared by admin - CRITICAL ACTION',
            'ip_address' => request()->ip(),
        ]);

        // Final security log
        \App\Services\SecurityLogger::logSecurityEvent(
            'admin.data_cleared',
            'CRITICAL: All platform data cleared by admin',
            $request->user(),
            $request
        );

        return response()->json(['message' => 'All platform data has been permanently deleted. Only admin accounts remain.']);
    }

    /**
     * Disable 2FA for a user (admin only)
     */
    public function disableUserTwoFactor(Request $request, User $user)
    {
        $this->authorize('admin');

        if (!$user->two_factor_enabled) {
            return response()->json(['message' => 'User does not have 2FA enabled'], 422);
        }

        // Log the action
        \App\Services\SecurityLogger::logSecurityEvent(
            'admin.2fa_disabled',
            "Admin disabled 2FA for user: {$user->email}",
            $user,
            $request
        );

        // Disable 2FA
        \App\Services\TwoFactorService::disable($user);

        return response()->json(['message' => '2FA disabled successfully']);
    }

    /**
     * Reset backup codes for a user (admin only)
     */
    public function resetUserBackupCodes(Request $request, User $user)
    {
        $this->authorize('admin');

        // Generate new backup codes
        $codes = \App\Services\TwoFactorService::generateBackupCodes($user);

        // Log the action
        \App\Services\SecurityLogger::logSecurityEvent(
            'admin.backup_codes_reset',
            "Admin reset backup codes for user: {$user->email}",
            $user,
            $request
        );

        return response()->json([
            'message' => 'Backup codes reset successfully',
            'codes_count' => count($codes),
            'has_backup_codes' => \App\Services\TwoFactorService::hasBackupCodes($user)
        ]);
    }

    public function feedback(Request $request)
    {
        $query = ActivityLog::where('action', 'like', 'support.%');

        // Map UI tab type → ActivityLog action
        $typeMap = [
            'feedback' => 'support.feedback',
            'features' => 'support.feature_request',
            'contact' => 'support.contact',
        ];

        if ($type = $request->type) {
            if (isset($typeMap[$type])) {
                $query->where('action', $typeMap[$type]);
            }
        }

        $perPage = (int) ($request->per_page ?? 20);
        $perPage = min(max($perPage, 1), 100);
        $paginator = $query->with('user:id,name,email')->latest()->paginate($perPage);

        // Transform ActivityLog rows into the shape the admin UI expects
        $items = collect($paginator->items())->map(function (ActivityLog $log) {
            $data = $log->data ?? [];
            $action = $log->action ?? '';
            $type = $data['type'] ?? match ($action) {
                'support.feedback' => 'feedback',
                'support.feature_request' => 'features',
                'support.contact' => 'contact',
                default => null,
            };

            // Derive subject & message from structured data first, then from description as fallback
            $subject = $data['subject'] ?? $data['title'] ?? null;
            $message = $data['message'] ?? $data['description'] ?? null;

            if (!$subject || !$message) {
                $desc = $log->description ?? '';
                // Simple parse: split on the first ':' and then '-' to approximate subject & message
                if (!$subject && str_contains($desc, ':')) {
                    [$prefix, $rest] = explode(':', $desc, 2);
                    $subject = trim($rest);
                }
                if (!$message && str_contains($desc, '-')) {
                    [, $msg] = explode('-', $desc, 2);
                    $message = trim($msg);
                }
            }

            return [
                'id' => $log->id,
                'type' => $type,
                'subject' => $subject,
                'message' => $message ?: $log->description,
                'user' => $log->user ? [
                    'id' => $log->user->id,
                    'name' => $log->user->name,
                    'email' => $log->user->email,
                ] : null,
                'created_at' => $log->created_at,
            ];
        });

        // Aggregate counts for stat cards
        $meta = [
            'total' => ActivityLog::where('action', 'like', 'support.%')->count(),
            'feedback_count' => ActivityLog::where('action', 'support.feedback')->count(),
            'feature_count' => ActivityLog::where('action', 'support.feature_request')->count(),
            'contact_count' => ActivityLog::where('action', 'support.contact')->count(),
        ];

        return response()->json([
            'data' => $items,
            'meta' => $meta,
        ]);
    }

    public function invoicesOverview(Request $request)
    {
        $query = Invoice::with(['business:id,name', 'client:id,name']);

        $status = $request->get('status');
        if ($status && $status !== 'all status') {
            $query->where('status', $status);
        }

        $perPage = (int) ($request->get('per_page') ?? 20);
        $perPage = min(max($perPage, 1), 100);
        $paginator = $query->orderByDesc('created_at')->paginate($perPage);

        $total = $paginator->total();
        $paidAmount = (float) Invoice::where('status', 'paid')->sum('total');
        $overdueAmount = (float) Invoice::where('status', 'overdue')->sum('total');

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'total' => $total,
                'paid_amount' => $paidAmount,
                'overdue_amount' => $overdueAmount,
                'platform_fees' => 0,
            ],
        ]);
    }

    public function getInvoice($id)
    {
        $invoice = Invoice::with([
            'business:id,name,email,phone,address',
            'client:id,name,email,phone,address',
            'items',
            'payments'
        ])->findOrFail($id);

        return response()->json($invoice);
    }

    public function backupDatabase(Request $request)
    {
        try {
            $backupPath = storage_path('app/backups');
            if (!is_dir($backupPath)) {
                mkdir($backupPath, 0755, true);
            }

            $filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
            $backupFile = $backupPath . '/' . $filename;

            // Get database connection details
            $dbHost = config('database.connections.mysql.host');
            $dbName = config('database.connections.mysql.database');
            $dbUser = config('database.connections.mysql.username');
            $dbPass = config('database.connections.mysql.password');

            // Create backup using mysqldump
            $command = sprintf(
                'mysqldump --host=%s --user=%s --password=%s --single-transaction --routines --triggers %s > %s',
                escapeshellarg($dbHost),
                escapeshellarg($dbUser),
                escapeshellarg($dbPass),
                escapeshellarg($dbName),
                escapeshellarg($backupFile)
            );

            exec($command, $output, $returnCode);

            if ($returnCode !== 0) {
                return response()->json(['message' => 'Backup failed'], 500);
            }

            // Log the backup action
            ActivityLog::create([
                'user_id' => auth()->id(),
                'action' => 'admin.database_backup',
                'description' => "Database backup created: {$filename}",
                'ip_address' => $request->ip(),
            ]);

            // Clean up old backups (keep last 10)
            $files = glob($backupPath . '/backup_*.sql');
            if (count($files) > 10) {
                usort($files, function($a, $b) {
                    return filemtime($b) - filemtime($a);
                });
                $filesToDelete = array_slice($files, 10);
                foreach ($filesToDelete as $file) {
                    unlink($file);
                }
            }

            return response()->json([
                'message' => 'Backup created successfully',
                'filename' => $filename,
                'size' => filesize($backupFile),
                'created_at' => now()->toIso8601String()
            ]);

        } catch (\Exception $e) {
            return response()->json(['message' => 'Backup failed: ' . $e->getMessage()], 500);
        }
    }

    public function optimizeTables(Request $request)
    {
        try {
            $tables = \DB::select('SHOW TABLES');
            $results = [];

            foreach ($tables as $table) {
                $tableName = array_values((array)$table)[0];
                
                // Optimize table
                $optimizeResult = \DB::statement("OPTIMIZE TABLE `{$tableName}`");
                
                // Get table status
                $status = \DB::select("SHOW TABLE STATUS LIKE '{$tableName}'");
                
                $results[] = [
                    'table' => $tableName,
                    'data_length' => $status[0]->Data_length ?? 0,
                    'index_length' => $status[0]->Index_length ?? 0,
                    'rows' => $status[0]->Rows ?? 0,
                    'optimized' => $optimizeResult !== false
                ];
            }

            // Log the optimization action
            ActivityLog::create([
                'user_id' => auth()->id(),
                'action' => 'admin.database_optimize',
                'description' => 'Database tables optimized',
                'ip_address' => $request->ip(),
            ]);

            $totalDataSize = array_sum(array_column($results, 'data_length'));
            $totalIndexSize = array_sum(array_column($results, 'index_length'));
            $totalRows = array_sum(array_column($results, 'rows'));

            return response()->json([
                'message' => 'Tables optimized successfully',
                'results' => $results,
                'summary' => [
                    'total_tables' => count($results),
                    'total_data_size' => $totalDataSize,
                    'total_index_size' => $totalIndexSize,
                    'total_rows' => $totalRows,
                    'total_size' => $totalDataSize + $totalIndexSize
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json(['message' => 'Optimization failed: ' . $e->getMessage()], 500);
        }
    }

    public function deleteUser(User $user, Request $request)
    {
        $this->checkAdminPermission($request, 'delete_users');
        
        if ($user->isAdmin()) {
            return response()->json(['message' => 'Cannot delete admin users'], 422);
        }

        $name = $user->name;
        $email = $user->email;

        // Delete all related businesses and their data
        foreach ($user->businesses as $business) {
            $business->invoices()->delete();
            $business->contracts()->delete();
            $business->clients()->delete();
            $business->expenses()->delete();
            $business->payments()->delete();
            $business->delete();
        }

        $user->tokens()->delete();
        $user->loginDevices()->delete();
        $user->delete();

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'admin.user_deleted',
            'description' => "User {$name} ({$email}) permanently deleted",
            'ip_address' => request()->ip(),
        ]);

        return response()->json(['message' => "User {$name} has been permanently deleted"]);
    }

    public function deleteBusiness($id)
    {
        $business = Business::findOrFail($id);
        $name = $business->name;

        $business->invoices()->delete();
        $business->contracts()->delete();
        $business->clients()->delete();
        $business->expenses()->delete();
        $business->payments()->delete();

        ActivityLog::where('business_id', $business->id)->delete();
        $business->delete();

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'admin.business_deleted',
            'description' => "Business {$name} permanently deleted",
            'ip_address' => request()->ip(),
        ]);

        return response()->json(['message' => "Business {$name} has been permanently deleted"]);
    }

    public function demographics()
    {
        // Device type distribution
        $deviceTypes = LoginDevice::selectRaw('device_type, COUNT(*) as count')
            ->whereNotNull('device_type')
            ->groupBy('device_type')
            ->orderByDesc('count')
            ->get();

        // Browser distribution
        $browsers = LoginDevice::selectRaw('browser, COUNT(*) as count')
            ->whereNotNull('browser')
            ->groupBy('browser')
            ->orderByDesc('count')
            ->get();

        // Platform / OS distribution
        $platforms = LoginDevice::selectRaw('platform, COUNT(*) as count')
            ->whereNotNull('platform')
            ->groupBy('platform')
            ->orderByDesc('count')
            ->get();

        // Country distribution
        $countries = LoginDevice::selectRaw('country, COUNT(*) as count')
            ->whereNotNull('country')
            ->groupBy('country')
            ->orderByDesc('count')
            ->get();

        // City distribution (top 20)
        $cities = LoginDevice::selectRaw('city, country, COUNT(*) as count')
            ->whereNotNull('city')
            ->groupBy('city', 'country')
            ->orderByDesc('count')
            ->limit(20)
            ->get();

        // Popular device names (phones)
        $deviceNames = LoginDevice::selectRaw('device_name, COUNT(*) as count')
            ->whereNotNull('device_name')
            ->groupBy('device_name')
            ->orderByDesc('count')
            ->limit(20)
            ->get();

        // User growth (last 12 months)
        $growth = [];
        for ($i = 11; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $growth[] = [
                'month' => $month->format('M Y'),
                'users' => User::whereYear('created_at', $month->year)->whereMonth('created_at', $month->month)->count(),
                'businesses' => Business::whereYear('created_at', $month->year)->whereMonth('created_at', $month->month)->count(),
            ];
        }

        // Plan distribution
        $plans = Business::selectRaw('plan, COUNT(*) as count')
            ->groupBy('plan')
            ->orderByDesc('count')
            ->get();

        // Industry distribution
        $industries = Business::selectRaw('industry, COUNT(*) as count')
            ->whereNotNull('industry')
            ->where('industry', '!=', '')
            ->groupBy('industry')
            ->orderByDesc('count')
            ->limit(20)
            ->get();

        // Active users (logged in within last 30 days)
        $activeUsers = User::where('last_login_at', '>=', now()->subDays(30))->count();
        $totalUsers = User::count();

        // Recommendations based on data
        $recommendations = $this->generateRecommendations($deviceTypes, $browsers, $platforms, $countries, $plans, $activeUsers, $totalUsers);

        return response()->json([
            'device_types' => $deviceTypes,
            'browsers' => $browsers,
            'platforms' => $platforms,
            'countries' => $countries,
            'cities' => $cities,
            'device_names' => $deviceNames,
            'growth' => $growth,
            'plans' => $plans,
            'industries' => $industries,
            'active_users_30d' => $activeUsers,
            'total_users' => $totalUsers,
            'retention_rate' => $totalUsers > 0 ? round(($activeUsers / $totalUsers) * 100, 1) : 0,
            'recommendations' => $recommendations,
        ]);
    }

    private function generateRecommendations($deviceTypes, $browsers, $platforms, $countries, $plans, $activeUsers, $totalUsers): array
    {
        $recs = [];
        $deviceMap = $deviceTypes->pluck('count', 'device_type')->toArray();
        $total = array_sum($deviceMap);

        $mobilePercent = $total > 0 ? (($deviceMap['mobile'] ?? 0) / $total) * 100 : 0;
        if ($mobilePercent > 40) {
            $recs[] = [
                'type' => 'mobile',
                'priority' => 'high',
                'title' => 'Mobile-first users',
                'message' => round($mobilePercent) . '% of users access from mobile. Prioritize mobile UX, push notifications, and consider a PWA or native app.',
            ];
        }

        $freeCount = $plans->where('plan', 'free')->first()?->count ?? 0;
        $totalBiz = $plans->sum('count');
        if ($totalBiz > 0 && ($freeCount / $totalBiz) > 0.7) {
            $recs[] = [
                'type' => 'conversion',
                'priority' => 'high',
                'title' => 'High free-plan ratio',
                'message' => round(($freeCount / $totalBiz) * 100) . '% of businesses are on the Free plan. Consider targeted upgrade campaigns, trial extensions, or feature previews.',
            ];
        }

        $retention = $totalUsers > 0 ? ($activeUsers / $totalUsers) * 100 : 0;
        if ($retention < 50 && $totalUsers > 10) {
            $recs[] = [
                'type' => 'retention',
                'priority' => 'medium',
                'title' => 'Low 30-day retention',
                'message' => 'Only ' . round($retention) . '% of users logged in within 30 days. Consider re-engagement emails, onboarding improvements, or feature announcements.',
            ];
        }

        $topBrowser = $browsers->first();
        if ($topBrowser && $topBrowser->count > ($total * 0.6)) {
            $recs[] = [
                'type' => 'browser',
                'priority' => 'low',
                'title' => 'Browser concentration',
                'message' => $topBrowser->browser . ' dominates at ' . round(($topBrowser->count / max($total, 1)) * 100) . '%. Ensure cross-browser compatibility testing.',
            ];
        }

        $topCountry = $countries->first();
        if ($topCountry) {
            $recs[] = [
                'type' => 'geo',
                'priority' => 'info',
                'title' => 'Primary market: ' . $topCountry->country,
                'message' => $topCountry->country . ' has the most users (' . $topCountry->count . '). Consider localized features, payment methods, and support hours for this market.',
            ];
        }

        if (empty($recs)) {
            $recs[] = [
                'type' => 'info',
                'priority' => 'info',
                'title' => 'Gathering data',
                'message' => 'More login data is needed to generate actionable recommendations. Insights will appear as users log in.',
            ];
        }

        return $recs;
    }

    public function getDatabaseStatus(Request $request)
    {
        try {
            // Get database size
            $sizeQuery = \DB::select("
                SELECT 
                    ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS 'size_mb'
                FROM information_schema.tables 
                WHERE table_schema = DATABASE()
            ");

            // Get total records
            $tables = \DB::select('SHOW TABLES');
            $totalRecords = 0;
            foreach ($tables as $table) {
                $tableName = array_values((array)$table)[0];
                $count = \DB::table($tableName)->count();
                $totalRecords += $count;
            }

            // Get last backup info
            $backupPath = storage_path('app/backups');
            $lastBackup = null;
            if (is_dir($backupPath)) {
                $files = glob($backupPath . '/backup_*.sql');
                if (!empty($files)) {
                    usort($files, function($a, $b) {
                        return filemtime($b) - filemtime($a);
                    });
                    $lastBackupFile = $files[0];
                    $lastBackup = [
                        'filename' => basename($lastBackupFile),
                        'size' => filesize($lastBackupFile),
                        'created_at' => date('Y-m-d H:i:s', filemtime($lastBackupFile))
                    ];
                }
            }

            return response()->json([
                'size_mb' => $sizeQuery[0]->size_mb ?? 0,
                'total_records' => $totalRecords,
                'total_tables' => count($tables),
                'last_backup' => $lastBackup,
                'database_name' => config('database.connections.mysql.database'),
                'charset' => config('database.connections.mysql.charset'),
                'collation' => config('database.connections.mysql.collation')
            ]);

        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to get database status: ' . $e->getMessage()], 500);
        }
    }

    // ─── Blog CRUD ───────────────────────────────────────────────────

    public function listPosts(Request $request)
    {
        $query = \App\Models\Post::with('author')->latest();
        if ($request->status) $query->where('status', $request->status);
        if ($request->search) $query->where('title', 'like', "%{$request->search}%");
        return \App\Http\Resources\PostResource::collection($query->paginate(15));
    }

    public function createPost(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'body' => 'required|string',
            'excerpt' => 'nullable|string|max:500',
            'cover_image' => 'nullable|string',
            'category' => 'nullable|string|max:100',
            'tags' => 'nullable|array',
            'status' => 'nullable|in:draft,published',
        ]);

        $post = \App\Models\Post::create([
            'title' => $request->title,
            'body' => $request->body,
            'excerpt' => $request->excerpt,
            'cover_image' => $request->cover_image,
            'category' => $request->category ?? 'general',
            'tags' => $request->tags ?? [],
            'status' => $request->status ?? 'draft',
            'published_at' => $request->status === 'published' ? now() : null,
            'author_id' => $request->user()->id,
        ]);

        return new \App\Http\Resources\PostResource($post->load('author'));
    }

    public function showPost($id)
    {
        $post = \App\Models\Post::with('author')->findOrFail($id);
        return new \App\Http\Resources\PostResource($post);
    }

    public function updatePost(Request $request, $id)
    {
        $post = \App\Models\Post::findOrFail($id);

        $request->validate([
            'title' => 'sometimes|string|max:255',
            'body' => 'sometimes|string',
            'excerpt' => 'nullable|string|max:500',
            'cover_image' => 'nullable|string',
            'category' => 'nullable|string|max:100',
            'tags' => 'nullable|array',
            'status' => 'nullable|in:draft,published',
        ]);

        $data = $request->only(['title', 'body', 'excerpt', 'cover_image', 'category', 'tags', 'status']);

        if (isset($data['status']) && $data['status'] === 'published' && !$post->published_at) {
            $data['published_at'] = now();
        }

        $post->update($data);
        return new \App\Http\Resources\PostResource($post->load('author'));
    }

    public function deletePost($id)
    {
        $post = \App\Models\Post::findOrFail($id);
        $post->delete();
        return response()->json(['message' => 'Post deleted']);
    }
}
