<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'description', 'amount', 'date', 'category',
        'payment_method', 'vendor', 'receipt_path', 'notes', 'status',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Get original values for audit trail
     */
    public function getOriginalValues(): array
    {
        return $this->only([
            'description', 'amount', 'date', 'category', 'payment_method', 'vendor', 'notes', 'status'
        ]);
    }
}
