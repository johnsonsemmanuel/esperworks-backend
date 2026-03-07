<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Onboarding Checklist Controller
 * Tracks user progress through onboarding steps to increase feature adoption by 40%
 */
class OnboardingController extends Controller
{
    /**
     * Get onboarding checklist status
     */
    public function getChecklist(Request $request)
    {
        $user = $request->user();
        $business = $user->businesses()->first();
        
        if (!$business) {
            $business = $user->teamBusinesses()->wherePivot('status', 'active')->first();
        }
        
        // Default checklist
        $defaultChecklist = [
            'create_business_profile' => false,
            'add_first_client' => false,
            'send_first_invoice' => false,
            'setup_payment_gateway' => false,
            'customize_branding' => false,
        ];
        
        $checklist = $user->onboarding_checklist ?? $defaultChecklist;
        
        // Auto-detect completed steps
        if ($business) {
            $checklist['create_business_profile'] = true;
            
            if ($business->clients()->exists()) {
                $checklist['add_first_client'] = true;
            }
            
            if ($business->invoices()->exists()) {
                $checklist['send_first_invoice'] = true;
            }
            
            if ($business->payment_verified && $business->paystack_subaccount_code) {
                $checklist['setup_payment_gateway'] = true;
            }
            
            if ($business->logo || ($business->branding && count($business->branding) > 0)) {
                $checklist['customize_branding'] = true;
            }
        }
        
        // Calculate progress
        $completed = count(array_filter($checklist));
        $total = count($checklist);
        $percentage = $total > 0 ? round(($completed / $total) * 100) : 0;
        
        return response()->json([
            'checklist' => $checklist,
            'progress' => [
                'completed' => $completed,
                'total' => $total,
                'percentage' => $percentage,
            ],
            'steps' => [
                [
                    'id' => 'create_business_profile',
                    'title' => 'Create your business profile',
                    'description' => 'Add your business details and logo',
                    'completed' => $checklist['create_business_profile'],
                    'action_url' => '/dashboard/settings',
                ],
                [
                    'id' => 'add_first_client',
                    'title' => 'Add your first client',
                    'description' => 'Add a client to start sending invoices',
                    'completed' => $checklist['add_first_client'],
                    'action_url' => '/dashboard/clients',
                ],
                [
                    'id' => 'send_first_invoice',
                    'title' => 'Send your first invoice',
                    'description' => 'Create and send a professional invoice',
                    'completed' => $checklist['send_first_invoice'],
                    'action_url' => '/dashboard/invoices/create',
                ],
                [
                    'id' => 'setup_payment_gateway',
                    'title' => 'Set up online payments',
                    'description' => 'Get paid faster with Paystack integration',
                    'completed' => $checklist['setup_payment_gateway'],
                    'action_url' => '/dashboard/settings?tab=payments',
                ],
                [
                    'id' => 'customize_branding',
                    'title' => 'Customize your branding',
                    'description' => 'Add your logo and brand colors',
                    'completed' => $checklist['customize_branding'],
                    'action_url' => '/dashboard/settings?tab=branding',
                ],
            ],
        ]);
    }
    
    /**
     * Update checklist item
     */
    public function updateChecklist(Request $request)
    {
        $request->validate([
            'step' => 'required|string|in:create_business_profile,add_first_client,send_first_invoice,setup_payment_gateway,customize_branding',
            'completed' => 'required|boolean',
        ]);
        
        $user = $request->user();
        $checklist = $user->onboarding_checklist ?? [];
        $checklist[$request->step] = $request->completed;
        
        $user->update(['onboarding_checklist' => $checklist]);
        
        return response()->json([
            'message' => 'Checklist updated',
            'checklist' => $checklist,
        ]);
    }
    
    /**
     * Dismiss onboarding checklist
     */
    public function dismissChecklist(Request $request)
    {
        $user = $request->user();
        $checklist = $user->onboarding_checklist ?? [];
        $checklist['dismissed'] = true;
        
        $user->update(['onboarding_checklist' => $checklist]);
        
        return response()->json(['message' => 'Onboarding checklist dismissed']);
    }
}
