<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PromoCode;
use App\Models\PromoCodeRedemption;
use App\Models\Business;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PromoCodeController extends Controller
{
    /**
     * Admin: List all promo codes with stats.
     */
    public function index(Request $request)
    {
        $query = PromoCode::withCount('redemptions');

        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('code', 'like', "%{$request->search}%")
                  ->orWhere('name', 'like', "%{$request->search}%");
            });
        }

        if ($request->type) {
            $query->where('type', $request->type);
        }

        if ($request->status === 'active') {
            $query->where('is_active', true)
                  ->where(function ($q) {
                      $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                  });
        } elseif ($request->status === 'expired') {
            $query->where(function ($q) {
                $q->where('is_active', false)
                  ->orWhere('expires_at', '<', now());
            });
        }

        $codes = $query->latest()->paginate($request->per_page ?? 20);

        return response()->json($codes);
    }

    /**
     * Admin: Create a new promo code.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'code' => 'nullable|string|max:30|unique:promo_codes,code',
            'description' => 'nullable|string|max:500',
            'type' => 'required|in:plan_upgrade,discount,trial_extension',
            'plan' => 'required_if:type,plan_upgrade|nullable|string',
            'plan_duration_days' => 'nullable|integer|min:1|max:365',
            'discount_percent' => 'required_if:type,discount|nullable|integer|min:1|max:100',
            'trial_days' => 'required_if:type,trial_extension|nullable|integer|min:1|max:365',
            'max_uses' => 'nullable|integer|min:0',
            'starts_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after_or_equal:starts_at',
        ]);

        $code = $request->code ?: $this->generateCode();

        $promo = PromoCode::create([
            'code' => strtoupper($code),
            'name' => $request->name,
            'description' => $request->description,
            'type' => $request->type,
            'plan' => $request->plan,
            'plan_duration_days' => $request->plan_duration_days ?? 30,
            'discount_percent' => $request->discount_percent,
            'trial_days' => $request->trial_days,
            'max_uses' => $request->max_uses ?? 0,
            'is_active' => true,
            'starts_at' => $request->starts_at,
            'expires_at' => $request->expires_at,
            'created_by' => auth()->id(),
        ]);

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'admin.promo_created',
            'description' => "Promo code '{$promo->code}' created: {$promo->name}",
            'ip_address' => request()->ip(),
        ]);

        return response()->json(['message' => 'Promo code created', 'promo_code' => $promo], 201);
    }

    /**
     * Admin: Show a single promo code with redemption history.
     */
    public function show(PromoCode $promoCode)
    {
        $promoCode->load(['redemptions.user:id,name,email', 'redemptions.business:id,name', 'creator:id,name']);
        $promoCode->loadCount('redemptions');

        return response()->json(['promo_code' => $promoCode]);
    }

    /**
     * Admin: Update a promo code.
     */
    public function update(Request $request, PromoCode $promoCode)
    {
        $request->validate([
            'name' => 'sometimes|string|max:100',
            'description' => 'nullable|string|max:500',
            'type' => 'sometimes|in:plan_upgrade,discount,trial_extension',
            'plan' => 'nullable|string',
            'plan_duration_days' => 'nullable|integer|min:1|max:365',
            'discount_percent' => 'nullable|integer|min:1|max:100',
            'trial_days' => 'nullable|integer|min:1|max:365',
            'max_uses' => 'nullable|integer|min:0',
            'is_active' => 'sometimes|boolean',
            'starts_at' => 'nullable|date',
            'expires_at' => 'nullable|date',
        ]);

        $promoCode->update($request->only([
            'name', 'description', 'type', 'plan', 'plan_duration_days',
            'discount_percent', 'trial_days', 'max_uses', 'is_active',
            'starts_at', 'expires_at',
        ]));

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'admin.promo_updated',
            'description' => "Promo code '{$promoCode->code}' updated",
            'ip_address' => request()->ip(),
        ]);

        return response()->json(['message' => 'Promo code updated', 'promo_code' => $promoCode->fresh()]);
    }

    /**
     * Admin: Delete a promo code.
     */
    public function destroy(PromoCode $promoCode)
    {
        $code = $promoCode->code;
        $promoCode->delete();

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'admin.promo_deleted',
            'description' => "Promo code '{$code}' deleted",
            'ip_address' => request()->ip(),
        ]);

        return response()->json(['message' => 'Promo code deleted']);
    }

    /**
     * Authenticated: List active, publicly available promo codes.
     * Returns only non-sensitive promo info so users know what's available.
     */
    public function available(Request $request)
    {
        $promos = PromoCode::where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->where(function ($q) {
                $q->where('max_uses', 0)->orWhereColumn('times_used', '<', 'max_uses');
            })
            ->get(['id', 'code', 'name', 'description', 'type', 'discount_percent', 'plan', 'trial_days', 'expires_at', 'max_uses', 'times_used']);

        $mapped = $promos->map(function ($p) {
            return [
                'id'             => $p->id,
                'code'           => $p->code,
                'description'    => $p->description ?? $p->name,
                'discount_type'  => $p->type === 'discount' ? 'percentage' : $p->type,
                'discount_value' => $p->discount_percent ?? 0,
                'expires_at'     => $p->expires_at?->toIso8601String(),
                'max_uses'       => $p->max_uses ?: null,
                'used_count'     => $p->times_used,
            ];
        });

        return response()->json(['promo_codes' => $mapped]);
    }

    /**
     * Public: Validate a promo code (check if it's valid without redeeming).
     */
    public function validate_code(Request $request)
    {
        $request->validate(['code' => 'required|string|max:30']);

        $promo = PromoCode::where('code', strtoupper(trim($request->code)))->first();

        if (!$promo) {
            return response()->json(['valid' => false, 'message' => 'Invalid promo code'], 404);
        }

        if (!$promo->isValid()) {
            $reason = 'This code is no longer valid';
            if (!$promo->is_active) $reason = 'This code has been deactivated';
            elseif ($promo->expires_at && $promo->expires_at->isPast()) $reason = 'This code has expired';
            elseif ($promo->max_uses > 0 && $promo->times_used >= $promo->max_uses) $reason = 'This code has reached its usage limit';

            return response()->json(['valid' => false, 'message' => $reason], 422);
        }

        // Check if current user already redeemed (if authenticated)
        if (auth()->check() && $promo->hasBeenRedeemedBy(auth()->id())) {
            return response()->json(['valid' => false, 'message' => 'You have already used this code'], 422);
        }

        $benefit = match ($promo->type) {
            'plan_upgrade' => "Upgrade to {$promo->plan} plan for {$promo->plan_duration_days} days",
            'discount' => "{$promo->discount_percent}% discount on your next billing",
            'trial_extension' => "Extend your trial by {$promo->trial_days} days",
            default => $promo->description,
        };

        return response()->json([
            'valid' => true,
            'code' => $promo->code,
            'name' => $promo->name,
            'type' => $promo->type,
            'benefit' => $benefit,
            'plan' => $promo->plan,
        ]);
    }

    /**
     * Authenticated user: Redeem a promo code for their active business.
     */
    public function redeem(Request $request)
    {
        $request->validate([
            'code' => 'required|string|max:30',
            'business_id' => 'required|integer|exists:businesses,id',
        ]);

        $user = $request->user();
        $promo = PromoCode::where('code', strtoupper(trim($request->code)))->first();

        if (!$promo) {
            return response()->json(['message' => 'Invalid promo code'], 404);
        }

        if (!$promo->isValid()) {
            return response()->json(['message' => 'This promo code is no longer valid'], 422);
        }

        if ($promo->hasBeenRedeemedBy($user->id)) {
            return response()->json(['message' => 'You have already used this promo code'], 422);
        }

        // Verify user owns the business
        $business = Business::where('id', $request->business_id)
            ->where('user_id', $user->id)
            ->first();

        if (!$business) {
            return response()->json(['message' => 'Business not found or access denied'], 403);
        }

        return $this->applyPromoCode($promo, $user, $business);
    }

    /**
     * Apply a promo code during registration (called internally from AuthController).
     */
    public static function applyAtRegistration(string $code, $user, Business $business): ?array
    {
        $promo = PromoCode::where('code', strtoupper(trim($code)))->first();

        if (!$promo || !$promo->isValid()) {
            return null;
        }

        $controller = new static;
        $result = $controller->applyPromoCode($promo, $user, $business, true);

        if ($result->getStatusCode() === 200) {
            return json_decode($result->getContent(), true);
        }

        return null;
    }

    /**
     * Core logic: apply a promo code to a user + business.
     */
    private function applyPromoCode(PromoCode $promo, $user, Business $business, bool $returnResponse = false)
    {
        $previousPlan = $business->plan;
        $newPlan = $previousPlan;
        $planExpiresAt = null;

        switch ($promo->type) {
            case 'plan_upgrade':
                $newPlan = $promo->plan;
                $planExpiresAt = now()->addDays($promo->plan_duration_days);
                $business->update([
                    'plan' => $newPlan,
                    'trial_ends_at' => $planExpiresAt,
                ]);
                break;

            case 'trial_extension':
                $currentTrialEnd = $business->trial_ends_at ?? now();
                if ($currentTrialEnd->isPast()) $currentTrialEnd = now();
                $planExpiresAt = $currentTrialEnd->addDays($promo->trial_days);
                $business->update(['trial_ends_at' => $planExpiresAt]);
                break;

            case 'discount':
                // Store discount info — actual billing integration would use this
                $planExpiresAt = now()->addDays(30);
                break;
        }

        // Record redemption
        PromoCodeRedemption::create([
            'promo_code_id' => $promo->id,
            'user_id' => $user->id,
            'business_id' => $business->id,
            'previous_plan' => $previousPlan,
            'new_plan' => $newPlan,
            'plan_expires_at' => $planExpiresAt,
        ]);

        // Increment usage
        $promo->increment('times_used');

        ActivityLog::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'action' => 'promo.redeemed',
            'description' => "Redeemed promo code '{$promo->code}': {$promo->name}",
            'ip_address' => request()->ip(),
        ]);

        $benefit = match ($promo->type) {
            'plan_upgrade' => "Your business has been upgraded to the {$newPlan} plan for {$promo->plan_duration_days} days!",
            'trial_extension' => "Your trial has been extended by {$promo->trial_days} days!",
            'discount' => "A {$promo->discount_percent}% discount has been applied to your account!",
            default => "Promo code applied successfully!",
        };

        return response()->json([
            'message' => $benefit,
            'promo_code' => $promo->code,
            'type' => $promo->type,
            'new_plan' => $newPlan,
            'previous_plan' => $previousPlan,
            'plan_expires_at' => $planExpiresAt?->toIso8601String(),
        ]);
    }

    private function generateCode(): string
    {
        do {
            $code = 'ESP-' . strtoupper(Str::random(6));
        } while (PromoCode::where('code', $code)->exists());
        return $code;
    }
}
