<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PromoCode extends Model
{
    protected $fillable = [
        'code', 'name', 'description', 'type', 'plan', 'plan_duration_days',
        'discount_percent', 'trial_days', 'max_uses', 'times_used',
        'is_active', 'starts_at', 'expires_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(PromoCodeRedemption::class);
    }

    /**
     * Check if this promo code is currently valid for use.
     */
    public function isValid(): bool
    {
        if (!$this->is_active) return false;
        if ($this->max_uses > 0 && $this->times_used >= $this->max_uses) return false;
        if ($this->starts_at && $this->starts_at->isFuture()) return false;
        if ($this->expires_at && $this->expires_at->isPast()) return false;
        return true;
    }

    /**
     * Check if a specific user has already redeemed this code.
     */
    public function hasBeenRedeemedBy(int $userId): bool
    {
        return $this->redemptions()->where('user_id', $userId)->exists();
    }
}
