<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Referral;
use App\Models\User;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ReferralController extends Controller
{
    /**
     * Get the current user's referral info.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // Generate referral code if not exists
        if (!$user->referral_code) {
            $user->referral_code = $this->generateUniqueCode($user);
            $user->save();
        }

        $referrals = Referral::where('referrer_id', $user->id)
            ->with('referred:id,name,email,created_at')
            ->latest()
            ->get();

        $totalReferrals = $referrals->count();
        $activeReferrals = $referrals->where('status', 'active')->count();
        $rewardedReferrals = $referrals->where('status', 'rewarded')->count();
        $pendingReferrals = $referrals->where('status', 'pending')->count();

        // Bonus features based on referral count
        $bonusFeatures = $this->calculateBonusFeatures($activeReferrals + $rewardedReferrals);

        $frontend = rtrim(config('app.frontend_url'), '/');
        $referralBase = $frontend ?: config('app.url');

        return response()->json([
            'referral_code' => $user->referral_code,
            'referral_link' => rtrim($referralBase, '/') . '/register?ref=' . $user->referral_code,
            'total_referrals' => $totalReferrals,
            'active_referrals' => $activeReferrals,
            'rewarded_referrals' => $rewardedReferrals,
            'pending_referrals' => $pendingReferrals,
            'bonus_features' => $bonusFeatures,
            'referrals' => $referrals->map(fn($r) => [
                'id' => $r->id,
                'name' => $r->referred->name ?? 'Unknown',
                'email' => $r->referred->email ?? '',
                'status' => $r->status,
                'reward_type' => $r->reward_type,
                'reward_detail' => $r->reward_detail,
                'joined_at' => $r->created_at->toDateTimeString(),
                'rewarded_at' => $r->rewarded_at?->toDateTimeString(),
            ]),
            'reward_tiers' => $this->getRewardTiers(),
        ]);
    }

    /**
     * Apply a referral code during registration (called internally).
     */
    public static function applyReferral(User $newUser, string $referralCode): void
    {
        $referrer = User::where('referral_code', $referralCode)->first();
        if (!$referrer || $referrer->id === $newUser->id) return;

        \Illuminate\Support\Facades\DB::transaction(function () use ($referrer, $newUser) {
            // Prevent duplicate
            if (Referral::where('referrer_id', $referrer->id)->where('referred_id', $newUser->id)->exists()) return;

            $newUser->update(['referred_by' => $referrer->id]);

            $referral = Referral::create([
                'referrer_id' => $referrer->id,
                'referred_id' => $newUser->id,
                'status' => 'active',
            ]);

            // Check and apply rewards
            $activeCount = Referral::where('referrer_id', $referrer->id)
                ->whereIn('status', ['active', 'rewarded'])
                ->count();

            $bonusFeatures = (new static)->calculateBonusFeatures($activeCount);
            $referrer->update(['referral_bonus_features' => $bonusFeatures]);

            // Mark milestone rewards
            $tiers = (new static)->getRewardTiers();
            foreach ($tiers as $tier) {
                if ($activeCount === $tier['count']) {
                    $referral->update([
                        'status' => 'rewarded',
                        'reward_type' => $tier['reward_type'],
                        'reward_detail' => $tier['reward'],
                        'rewarded_at' => now(),
                    ]);

                    ActivityLog::create([
                        'user_id' => $referrer->id,
                        'action' => 'referral.reward',
                        'description' => "Earned referral reward: {$tier['reward']} ({$activeCount} referrals)",
                        'ip_address' => request()->ip(),
                    ]);
                    break;
                }
            }
        });
    }

    /**
     * Regenerate referral code.
     */
    public function regenerateCode(Request $request)
    {
        $user = $request->user();
        $user->referral_code = $this->generateUniqueCode($user);
        $user->save();

        return response()->json([
            'referral_code' => $user->referral_code,
            'referral_link' => config('app.frontend_url', 'https://esperworks.com') . '/register?ref=' . $user->referral_code,
        ]);
    }

    private function generateUniqueCode(User $user): string
    {
        $base = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $user->name), 0, 4));
        if (strlen($base) < 3) $base = 'ESP';
        do {
            $code = $base . '-' . strtoupper(Str::random(5));
        } while (User::where('referral_code', $code)->exists());
        return $code;
    }

    private function calculateBonusFeatures(int $activeReferrals): array
    {
        $features = [];
        if ($activeReferrals >= 1) $features[] = 'extra_5_invoices';
        if ($activeReferrals >= 3) $features[] = 'custom_branding';
        if ($activeReferrals >= 5) $features[] = 'extra_business';
        if ($activeReferrals >= 10) $features[] = 'priority_support';
        if ($activeReferrals >= 15) $features[] = 'advanced_reports';
        if ($activeReferrals >= 25) $features[] = 'api_access';
        return $features;
    }

    private function getRewardTiers(): array
    {
        return [
            ['count' => 1, 'reward' => '+5 extra invoices/month', 'reward_type' => 'feature_unlock', 'icon' => 'file-text'],
            ['count' => 3, 'reward' => 'Custom branding unlocked', 'reward_type' => 'feature_unlock', 'icon' => 'palette'],
            ['count' => 5, 'reward' => '+1 extra business slot', 'reward_type' => 'feature_unlock', 'icon' => 'building'],
            ['count' => 10, 'reward' => 'Priority support access', 'reward_type' => 'feature_unlock', 'icon' => 'headphones'],
            ['count' => 15, 'reward' => 'Advanced reports & analytics', 'reward_type' => 'feature_unlock', 'icon' => 'bar-chart'],
            ['count' => 25, 'reward' => 'API access unlocked', 'reward_type' => 'feature_unlock', 'icon' => 'code'],
        ];
    }
}
