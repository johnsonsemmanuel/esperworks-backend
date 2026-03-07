<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'user_id', 'name', 'email', 'phone', 'address',
        'city', 'country', 'company', 'portal_invited', 'portal_invited_at',
        'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'portal_invited' => 'boolean',
            'portal_invited_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function totalRevenue(): float
    {
        return $this->invoices()->where('status', 'paid')->sum('total');
    }

    public function outstandingAmount(): float
    {
        return $this->invoices()->whereIn('status', ['sent', 'viewed', 'overdue'])->sum('total')
            - $this->invoices()->whereIn('status', ['sent', 'viewed', 'overdue'])->sum('amount_paid');
    }
}
