<?php

namespace App\Services;

use App\Models\User;
use App\Models\Business;
use App\Models\TeamMember;

class TeamRoleService
{
    /**
     * Role hierarchy with permission levels
     */
    const ROLE_HIERARCHY = [
        'viewer' => 1,
        'staff' => 2,
        'accountant' => 3,
        'admin' => 4,
        'owner' => 5, // Business owner (not assignable)
    ];

    /**
     * Role permissions
     */
    const ROLE_PERMISSIONS = [
        'viewer' => [
            'view_invoices' => true,
            'view_contracts' => true,
            'view_clients' => true,
            'view_payments' => true,
            'view_reports' => true,
            'export_data' => false,
            'create_invoices' => false,
            'edit_invoices' => false,
            'delete_invoices' => false,
            'create_contracts' => false,
            'edit_contracts' => false,
            'delete_contracts' => false,
            'manage_clients' => false,
            'manage_team' => false,
            'manage_settings' => false,
        ],
        'staff' => [
            'view_invoices' => true,
            'view_contracts' => true,
            'view_clients' => true,
            'view_payments' => true,
            'view_reports' => true,
            'export_data' => true,
            'create_invoices' => true,
            'edit_invoices' => true,
            'delete_invoices' => false,
            'create_contracts' => false,
            'edit_contracts' => false,
            'delete_contracts' => false,
            'manage_clients' => true,
            'manage_team' => false,
            'manage_settings' => false,
        ],
        'accountant' => [
            'view_invoices' => true,
            'view_contracts' => true,
            'view_clients' => true,
            'view_payments' => true,
            'view_reports' => true,
            'export_data' => true,
            'create_invoices' => true,
            'edit_invoices' => true,
            'delete_invoices' => false,
            'create_contracts' => false,
            'edit_contracts' => false,
            'delete_contracts' => false,
            'manage_clients' => false,
            'manage_team' => false,
            'manage_settings' => false,
        ],
        'admin' => [
            'view_invoices' => true,
            'view_contracts' => true,
            'view_clients' => true,
            'view_payments' => true,
            'view_reports' => true,
            'export_data' => true,
            'create_invoices' => true,
            'edit_invoices' => true,
            'delete_invoices' => true,
            'create_contracts' => true,
            'edit_contracts' => true,
            'delete_contracts' => true,
            'manage_clients' => true,
            'manage_team' => true,
            'manage_settings' => true,
        ],
    ];

    /**
     * Check if user can assign specific role
     */
    public static function canAssignRole(User $assigner, string $targetRole): bool
    {
        // Business owners can assign any role except owner
        if ($assigner->isBusinessOwner()) {
            return in_array($targetRole, ['viewer', 'staff', 'accountant', 'admin']);
        }

        // Team members can only assign roles lower than their own
        $assignerRole = self::getHighestRole($assigner);
        $assignerLevel = self::ROLE_HIERARCHY[$assignerRole] ?? 0;
        $targetLevel = self::ROLE_HIERARCHY[$targetRole] ?? 0;

        return $targetLevel < $assignerLevel;
    }

    /**
     * Check if user can modify role of team member
     */
    public static function canModifyRole(User $modifier, TeamMember $targetMember, string $newRole): bool
    {
        // Cannot modify business owner
        if ($targetMember->user->isBusinessOwner()) {
            return false;
        }

        // Cannot modify own role
        if ($modifier->id === $targetMember->user_id) {
            return false;
        }

        return self::canAssignRole($modifier, $newRole);
    }

    /**
     * Validate role assignment
     */
    public static function validateRoleAssignment(User $assigner, string $targetRole, Business $business): array
    {
        $errors = [];

        // Check if role exists
        if (!isset(self::ROLE_HIERARCHY[$targetRole])) {
            $errors[] = 'Invalid role specified';
            return $errors;
        }

        // Check if assigner can assign this role
        if (!self::canAssignRole($assigner, $targetRole)) {
            $errors[] = 'You do not have permission to assign this role';
        }

        // Check business team size limits
        $currentTeamSize = TeamMember::where('business_id', $business->id)->count();
        $maxTeamSize = self::getMaxTeamSize($business);
        
        if ($currentTeamSize >= $maxTeamSize) {
            $errors[] = 'Team size limit reached for your plan';
        }

        // Check admin role limits (max 2 admins per business)
        if ($targetRole === 'admin') {
            $adminCount = TeamMember::where('business_id', $business->id)
                ->where('role', 'admin')
                ->count();
            
            if ($adminCount >= 2) {
                $errors[] = 'Maximum of 2 admin roles allowed per business';
            }
        }

        return $errors;
    }

    /**
     * Get user's highest role across all businesses
     */
    public static function getHighestRole(User $user): string
    {
        if ($user->isBusinessOwner()) {
            return 'owner';
        }

        $roles = $user->teamMemberships()
            ->where('status', 'active')
            ->pluck('role')
            ->toArray();

        $highestLevel = 0;
        $highestRole = 'viewer';

        foreach ($roles as $role) {
            $level = self::ROLE_HIERARCHY[$role] ?? 0;
            if ($level > $highestLevel) {
                $highestLevel = $level;
                $highestRole = $role;
            }
        }

        return $highestRole;
    }

    /**
     * Check if user has specific permission for business
     */
    public static function hasPermission(User $user, Business $business, string $permission): bool
    {
        // Business owners have all permissions
        if ($user->id === $business->user_id) {
            return true;
        }

        $role = $user->teamRoleForBusiness($business->id);
        if (!$role) {
            return false;
        }

        return self::ROLE_PERMISSIONS[$role][$permission] ?? false;
    }

    /**
     * Get maximum team size based on business plan
     */
    private static function getMaxTeamSize(Business $business): int
    {
        // This would typically check the business's subscription plan
        // For now, using reasonable defaults
        $plan = $business->subscription_plan ?? 'starter';
        
        switch ($plan) {
            case 'starter':
                return 3;
            case 'professional':
                return 10;
            case 'enterprise':
                return 50;
            default:
                return 3;
        }
    }

    /**
     * Get role description
     */
    public static function getRoleDescription(string $role): string
    {
        $descriptions = [
            'viewer' => 'Can view all data but cannot make changes',
            'staff' => 'Can create and edit invoices, manage clients',
            'accountant' => 'Can manage invoices and financial data',
            'admin' => 'Full access except billing and account settings',
        ];

        return $descriptions[$role] ?? 'Unknown role';
    }

    /**
     * Get all available roles with descriptions
     */
    public static function getAvailableRoles(): array
    {
        $roles = [];
        foreach (self::ROLE_HIERARCHY as $role => $level) {
            if ($role !== 'owner') { // Don't include owner in assignable roles
                $roles[$role] = [
                    'level' => $level,
                    'description' => self::getRoleDescription($role),
                    'permissions' => self::ROLE_PERMISSIONS[$role] ?? []
                ];
            }
        }
        
        return $roles;
    }

    /**
     * Log role changes for audit
     */
    public static function logRoleChange(User $changer, TeamMember $member, string $oldRole, string $newRole): void
    {
        SecurityLogger::logSecurityEvent(
            'team.role.changed',
            "Role changed for {$member->user->email}: {$oldRole} → {$newRole}",
            $changer
        );

        ActivityLog::log(
            'team.role_changed',
            "{$changer->name} changed {$member->user->name}'s role from {$oldRole} to {$newRole}",
            $member->business
        );
    }
}
