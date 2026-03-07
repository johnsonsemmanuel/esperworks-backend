<?php

namespace App\Services;

use App\Models\User;
use App\Models\Business;
use App\Models\TeamMember;
use App\Mail\TeamInvitationMail;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class TeamInviteService
{
    /**
     * Generate secure team invitation
     */
    public static function createInvitation(Business $business, string $email, string $name, string $role): array
    {
        // Generate unique invitation token
        $token = Str::random(32);
        
        // Store invitation data with expiry (7 days)
        $inviteData = [
            'business_id' => $business->id,
            'email' => strtolower($email),
            'name' => $name,
            'role' => $role,
            'invited_by' => auth()->id(),
            'created_at' => now(),
            'expires_at' => now()->addDays(7)
        ];
        
        Cache::put("team_invite_{$token}", $inviteData, now()->addDays(7));
        
        // Send invitation email
        try {
            $inviteUrl = config('app.frontend_url') . "/team-invite?token={$token}";
            Mail::to($email)->send(new TeamInvitationMail($name, $business->name, $role, $inviteUrl));
            
            SecurityLogger::logSecurityEvent('team.invite.created', 
                "Team invitation created for {$email} to {$business->name}", 
                auth()->user()
            );
            
            return [
                'token' => $token,
                'expires_at' => $inviteData['expires_at']
            ];
        } catch (\Exception $e) {
            Cache::forget("team_invite_{$token}");
            SecurityLogger::logSecurityEvent('team.invite.failed', 
                "Failed to send team invitation: {$e->getMessage()}", 
                auth()->user()
            );
            throw new \Exception('Failed to send invitation email');
        }
    }
    
    /**
     * Accept team invitation
     */
    public static function acceptInvitation(string $token, ?User $user = null): array
    {
        $inviteData = Cache::get("team_invite_{$token}");
        
        if (!$inviteData) {
            throw new \Exception('Invalid or expired invitation');
        }
        
        if (now()->isAfter($inviteData['expires_at'])) {
            Cache::forget("team_invite_{$token}");
            throw new \Exception('Invitation has expired');
        }
        
        // Check if user already exists
        if (!$user) {
            $user = User::where('email', $inviteData['email'])->first();
        }
        
        // Create user if doesn't exist
        if (!$user) {
            $user = User::create([
                'name' => $inviteData['name'],
                'email' => $inviteData['email'],
                'password' => Hash::make(Str::random(32)), // Random password, will be reset
                'role' => 'business_owner', // Will be overridden by team membership
                'status' => 'active',
                'must_change_password' => true,
                'email_verified_at' => now(),
            ]);
        }
        
        // Check if already team member
        $existingMember = TeamMember::where('business_id', $inviteData['business_id'])
            ->where('user_id', $user->id)
            ->first();
            
        if ($existingMember) {
            Cache::forget("team_invite_{$token}");
            throw new \Exception('User is already a team member');
        }
        
        // Create team membership
        $member = TeamMember::create([
            'business_id' => $inviteData['business_id'],
            'user_id' => $user->id,
            'role' => $inviteData['role'],
            'status' => 'active',
        ]);
        
        // Log activity
        $business = Business::find($inviteData['business_id']);
        ActivityLog::log(
            'team.joined',
            "{$user->name} joined {$business->name} as {$inviteData['role']}",
            $business
        );
        
        SecurityLogger::logSecurityEvent('team.invite.accepted', 
            "Team invitation accepted by {$user->email} for {$business->name}", 
            $user
        );
        
        // Remove invitation token
        Cache::forget("team_invite_{$token}");
        
        return [
            'user' => $user,
            'member' => $member,
            'business' => $business
        ];
    }
    
    /**
     * Revoke team invitation
     */
    public static function revokeInvitation(string $token): bool
    {
        $inviteData = Cache::get("team_invite_{$token}");
        
        if ($inviteData) {
            Cache::forget("team_invite_{$token}");
            
            SecurityLogger::logSecurityEvent('team.invite.revoked', 
                "Team invitation revoked for {$inviteData['email']}", 
                auth()->user()
            );
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Get invitation details
     */
    public static function getInvitation(string $token): ?array
    {
        $inviteData = Cache::get("team_invite_{$token}");
        
        if (!$inviteData || now()->isAfter($inviteData['expires_at'])) {
            if ($inviteData) {
                Cache::forget("team_invite_{$token}");
            }
            return null;
        }
        
        $business = Business::find($inviteData['business_id']);
        
        return [
            'email' => $inviteData['email'],
            'name' => $inviteData['name'],
            'role' => $inviteData['role'],
            'business' => $business,
            'expires_at' => $inviteData['expires_at']
        ];
    }
}
