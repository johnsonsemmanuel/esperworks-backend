<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Facades\Storage;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens;

    protected $appends = ['avatar_url'];

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'phone',
        'avatar',
        'status',
        'last_login_at',
        'must_change_password',
        'email_verification_code',
        'email_verified_at',
        'referral_code',
        'referred_by',
        'referral_bonus_features',
        'login_attempts',
        'locked_until',
        'notification_preferences',
        'two_factor_enabled',
        'two_factor_enabled_at',
        'two_factor_backup_codes',
        'admin_role', // New field for admin privilege levels
        'admin_permissions', // New field for specific admin permissions
        'onboarding_checklist', // Onboarding progress tracking
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'email_verification_code',
        'two_factor_backup_codes',
        'login_attempts',
        'locked_until',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'two_factor_enabled_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'referral_bonus_features' => 'array',
            'notification_preferences' => 'array',
            'two_factor_enabled' => 'boolean',
            'locked_until' => 'datetime',
            'admin_permissions' => 'array',
            'onboarding_checklist' => 'array',
        ];
    }

    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class);
    }

    public function teamMemberships(): HasMany
    {
        return $this->hasMany(TeamMember::class);
    }

    public function teamBusinesses(): BelongsToMany
    {
        return $this->belongsToMany(Business::class, 'team_members')
            ->withPivot(['role', 'status'])
            ->withTimestamps();
    }

    public function clientProfiles(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function loginDevices(): HasMany
    {
        return $this->hasMany(LoginDevice::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isBusinessOwner(): bool
    {
        return $this->role === 'business_owner';
    }

    public function isClient(): bool
    {
        return $this->role === 'client';
    }

    public function isTeamMember(): bool
    {
        return $this->teamMemberships()->where('status', 'active')->exists();
    }

    public function teamRoleForBusiness(int $businessId): ?string
    {
        return $this->teamMemberships()
            ->where('business_id', $businessId)
            ->where('status', 'active')
            ->value('role');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isLocked(): bool
    {
        return $this->locked_until && $this->locked_until->isFuture();
    }

    public function lockAccount(int $minutes = 15): void
    {
        $this->update([
            'locked_until' => now()->addMinutes($minutes),
            'login_attempts' => 0,
        ]);
    }

    public function incrementLoginAttempts(): void
    {
        $this->increment('login_attempts');

        // Lock account after 5 failed attempts
        if ($this->login_attempts >= 5) {
            $this->lockAccount(15); // Lock for 15 minutes
        }
    }

    public function resetLoginAttempts(): void
    {
        $this->update([
            'login_attempts' => 0,
            'locked_until' => null,
        ]);
    }

    public function getAvatarUrlAttribute(): ?string
    {
        try {
            return $this->avatar ? Storage::disk('public')->url($this->avatar) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // Admin role methods
    public function getAdminRole(): string
    {
        return $this->admin_role ?? 'viewer';
    }

    public function hasAdminPermission(string $permission): bool
    {
        if (!$this->isAdmin()) {
            return false;
        }

        $role = $this->getAdminRole();
        $permissions = $this->admin_permissions ?? [];

        // Super admin has all permissions
        if ($role === 'super_admin') {
            return true;
        }

        // Check role-based permissions
        $rolePermissions = self::getAdminRolePermissions($role);
        if (in_array($permission, $rolePermissions)) {
            return true;
        }

        // Check explicit permissions
        return in_array($permission, $permissions);
    }

    public static function getAdminRolePermissions(string $role): array
    {
        $permissions = [
            'viewer' => [
                'view_dashboard',
                'view_users',
                'view_businesses',
                'view_invoices',
                'view_activity_logs',
                'view_settings'
            ],
            'operator' => [
                'view_dashboard',
                'view_users',
                'view_businesses',
                'view_invoices',
                'view_activity_logs',
                'view_settings',
                'suspend_users',
                'activate_users',
                'suspend_businesses',
                'activate_businesses',
                'view_feedback'
            ],
            'manager' => [
                'view_dashboard',
                'view_users',
                'view_businesses',
                'view_invoices',
                'view_activity_logs',
                'view_settings',
                'suspend_users',
                'activate_users',
                'suspend_businesses',
                'activate_businesses',
                'change_plans',
                'view_feedback',
                'manage_pricing',
                'manage_settings'
            ],
            'super_admin' => [
                'all' // Super admin has all permissions
            ]
        ];

        return $permissions[$role] ?? [];
    }

    public static function getAdminRoles(): array
    {
        return [
            'viewer' => 'Can view all platform data but cannot make changes',
            'operator' => 'Can manage users and businesses (suspend/activate)',
            'manager' => 'Can manage users, businesses, plans, and settings',
            'super_admin' => 'Full access to all admin functions'
        ];
    }
}
