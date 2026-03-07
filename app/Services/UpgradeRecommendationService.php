<?php

namespace App\Services;

use App\Models\Business;

/**
 * Service for generating intelligent upgrade recommendations with ROI calculations
 * Based on business analysis recommendations to increase conversion rates by 30-50%
 */
class UpgradeRecommendationService
{
    /**
     * Generate upgrade message with ROI calculation
     */
    public static function generateUpgradeMessage(Business $business, string $resourceType, array $context = []): array
    {
        $plan = $business->plan ?? 'free';
        $planName = Business::planDisplayName($plan);
        $limits = $business->getPlanLimits();
        $usage = $context['usage'] ?? 0;
        $limit = $context['limit'] ?? $limits[$resourceType] ?? 0;
        
        // Calculate ROI based on resource type
        $roi = self::calculateROI($business, $resourceType, $context);
        
        // Get next recommended plan
        $nextPlan = self::getNextPlan($plan);
        $nextPlanName = Business::planDisplayName($nextPlan);
        $nextPlanPrice = self::getPlanPrice($nextPlan);
        $nextPlanLimits = Business::getPlanLimitsForPlan($nextPlan);
        $nextPlanLimit = $nextPlanLimits[$resourceType] ?? -1;
        
        // Generate contextual message
        $message = self::generateContextualMessage($resourceType, $usage, $limit, $nextPlanName, $nextPlanLimit, $roi);
        
        return [
            'message' => $message,
            'current_plan' => $plan,
            'current_plan_name' => $planName,
            'recommended_plan' => $nextPlan,
            'recommended_plan_name' => $nextPlanName,
            'recommended_plan_price' => $nextPlanPrice,
            'upgrade_required' => true,
            'roi_data' => $roi,
            'usage' => $usage,
            'limit' => $limit,
            'next_limit' => $nextPlanLimit,
            'resource_type' => $resourceType,
        ];
    }
    
    /**
     * Calculate ROI for upgrade decision
     */
    private static function calculateROI(Business $business, string $resourceType, array $context): array
    {
        $roi = [
            'current_value' => 0,
            'potential_value' => 0,
            'monthly_cost' => 0,
            'roi_multiple' => 0,
            'message' => '',
        ];
        
        switch ($resourceType) {
            case 'invoices':
                // Calculate average invoice value
                $avgInvoiceValue = $business->invoices()
                    ->where('status', 'paid')
                    ->whereMonth('created_at', now()->month)
                    ->avg('total') ?? 0;
                
                $invoicesSent = $business->invoices()
                    ->whereMonth('created_at', now()->month)
                    ->count();
                
                $currentValue = $avgInvoiceValue * $invoicesSent;
                $nextPlan = self::getNextPlan($business->plan ?? 'free');
                $nextPlanPrice = self::getPlanPrice($nextPlan);
                $nextPlanLimits = Business::getPlanLimitsForPlan($nextPlan);
                $nextLimit = $nextPlanLimits['invoices'] ?? 50;
                
                // Calculate potential value if they could send more invoices
                $potentialValue = $nextLimit === -1 
                    ? $avgInvoiceValue * 100 // Assume 100 invoices for unlimited
                    : $avgInvoiceValue * $nextLimit;
                
                $roi['current_value'] = round($currentValue, 2);
                $roi['potential_value'] = round($potentialValue, 2);
                $roi['monthly_cost'] = $nextPlanPrice;
                $roi['roi_multiple'] = $nextPlanPrice > 0 ? round($potentialValue / $nextPlanPrice, 1) : 0;
                
                if ($avgInvoiceValue > 0) {
                    $currency = $business->currency ?? 'GHS';
                    $roi['message'] = sprintf(
                        "Your average invoice is %s %.2f. Upgrade to %s for %s %.2f/month to send %s invoices (potential %s %.2f revenue).",
                        $currency,
                        $avgInvoiceValue,
                        Business::planDisplayName($nextPlan),
                        $currency,
                        $nextPlanPrice,
                        $nextLimit === -1 ? 'unlimited' : $nextLimit,
                        $currency,
                        $potentialValue
                    );
                }
                break;
                
            case 'clients':
                $clientCount = $business->clients()->count();
                $avgRevenuePerClient = $business->invoices()
                    ->where('status', 'paid')
                    ->whereYear('created_at', now()->year)
                    ->sum('total') / max($clientCount, 1);
                
                $nextPlan = self::getNextPlan($business->plan ?? 'free');
                $nextPlanPrice = self::getPlanPrice($nextPlan);
                $nextPlanLimits = Business::getPlanLimitsForPlan($nextPlan);
                $nextLimit = $nextPlanLimits['clients'] ?? 50;
                
                $currentValue = $avgRevenuePerClient * $clientCount;
                $potentialValue = $nextLimit === -1 
                    ? $avgRevenuePerClient * ($clientCount + 20) // Assume 20 more clients
                    : $avgRevenuePerClient * $nextLimit;
                
                $roi['current_value'] = round($currentValue, 2);
                $roi['potential_value'] = round($potentialValue, 2);
                $roi['monthly_cost'] = $nextPlanPrice;
                $roi['roi_multiple'] = $nextPlanPrice > 0 ? round(($potentialValue - $currentValue) / $nextPlanPrice, 1) : 0;
                
                if ($avgRevenuePerClient > 0) {
                    $currency = $business->currency ?? 'GHS';
                    $roi['message'] = sprintf(
                        "Each client generates ~%s %.2f/year. Upgrade to add %s clients.",
                        $currency,
                        $avgRevenuePerClient,
                        $nextLimit === -1 ? 'unlimited' : ($nextLimit - $clientCount)
                    );
                }
                break;
                
            case 'contracts':
            case 'proposals':
                $avgContractValue = $business->contracts()
                    ->where('type', $resourceType === 'proposals' ? 'proposal' : 'contract')
                    ->whereMonth('created_at', now()->month)
                    ->avg('value') ?? 0;
                
                $nextPlan = self::getNextPlan($business->plan ?? 'free');
                $nextPlanPrice = self::getPlanPrice($nextPlan);
                
                if ($avgContractValue > 0) {
                    $currency = $business->currency ?? 'GHS';
                    $roi['message'] = sprintf(
                        "Your average %s value is %s %.2f. Upgrade to send professional, watermark-free documents.",
                        $resourceType === 'proposals' ? 'proposal' : 'contract',
                        $currency,
                        $avgContractValue
                    );
                }
                break;
        }
        
        return $roi;
    }
    
    /**
     * Generate contextual upgrade message
     */
    private static function generateContextualMessage(
        string $resourceType, 
        int $usage, 
        int $limit, 
        string $nextPlanName, 
        int $nextLimit,
        array $roi
    ): string {
        $baseMessage = "You're operating at full capacity. Upgrade to keep workflows uninterrupted.";
        
        // If we have ROI data, use it
        if (!empty($roi['message'])) {
            return $roi['message'];
        }
        
        // Otherwise, generate contextual message
        $limitText = $nextLimit === -1 ? 'unlimited' : $nextLimit;
        
        $messages = [
            'invoices' => "You've reached your invoice limit ({$usage}/{$limit}). Upgrade to {$nextPlanName} for {$limitText} invoices per month.",
            'clients' => "You've reached your client limit ({$usage}/{$limit}). Upgrade to {$nextPlanName} to add {$limitText} clients.",
            'contracts' => "You've reached your contract limit. Upgrade to {$nextPlanName} for {$limitText} contracts per month.",
            'proposals' => "You've reached your proposal limit. Upgrade to {$nextPlanName} for {$limitText} proposals per month.",
            'team_members' => "You've reached your team member limit. Upgrade to {$nextPlanName} to add {$limitText} team members.",
            'storage_gb' => "You've reached your storage limit. Upgrade to {$nextPlanName} for {$limitText}GB storage.",
        ];
        
        return $messages[$resourceType] ?? $baseMessage;
    }
    
    /**
     * Get next recommended plan
     */
    private static function getNextPlan(string $currentPlan): string
    {
        $planOrder = ['free', 'starter', 'pro', 'enterprise'];
        $currentIndex = array_search($currentPlan, $planOrder);
        
        if ($currentIndex === false || $currentIndex >= count($planOrder) - 1) {
            return 'enterprise';
        }
        
        return $planOrder[$currentIndex + 1];
    }
    
    /**
     * Get plan price
     */
    private static function getPlanPrice(string $plan): float
    {
        $pricing = Business::getPricingConfig();
        
        foreach ($pricing['plans'] ?? [] as $p) {
            if (($p['id'] ?? null) === $plan) {
                return (float) ($p['price'] ?? 0);
            }
        }
        
        // Fallback prices
        return match($plan) {
            'starter' => 25,
            'pro' => 49,
            'enterprise' => 149,
            default => 0,
        };
    }
    
    /**
     * Get usage percentage for a resource
     */
    public static function getUsagePercentage(Business $business, string $resourceType): array
    {
        $limits = $business->getPlanLimits();
        $limit = $limits[$resourceType] ?? 0;
        
        if ($limit === -1) {
            return [
                'usage' => 0,
                'limit' => -1,
                'percentage' => 0,
                'status' => 'unlimited',
            ];
        }
        
        $usage = match($resourceType) {
            'invoices' => $business->invoices()->whereMonth('created_at', now()->month)->count(),
            'clients' => $business->clients()->count(),
            'contracts' => $business->contracts()->where('type', 'contract')->whereMonth('created_at', now()->month)->count(),
            'proposals' => $business->contracts()->where('type', 'proposal')->whereMonth('created_at', now()->month)->count(),
            'expenses' => $business->expenses()->whereMonth('created_at', now()->month)->count(),
            'storage_gb' => $business->getStorageUsed(),
            default => 0,
        };
        
        $percentage = $limit > 0 ? round(($usage / $limit) * 100, 1) : 0;
        
        $status = match(true) {
            $percentage >= 100 => 'exceeded',
            $percentage >= 80 => 'warning',
            $percentage >= 50 => 'moderate',
            default => 'healthy',
        };
        
        return [
            'usage' => $usage,
            'limit' => $limit,
            'percentage' => $percentage,
            'status' => $status,
            'should_nudge' => $percentage >= 80,
        ];
    }
}
