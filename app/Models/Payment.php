<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'invoice_id', 'client_id', 'amount', 'currency',
        'method', 'reference', 'paystack_reference', 'paystack_access_code',
        'status', 'metadata', 'paid_at',
    ];

    protected $hidden = [
        'paystack_access_code', 'metadata',
    ];

    protected $appends = ['bank_reference'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function getBankReferenceAttribute(): ?string
    {
        if ($this->method === 'bank_transfer' && $this->reference) {
            return $this->reference;
        }
        return array_filter((array) ($this->metadata ?? []))['bank_reference'] ?? null;
    }
}
