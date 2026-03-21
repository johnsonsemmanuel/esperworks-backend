<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Bill extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'supplier_name', 'supplier_email', 'bill_number',
        'status', 'category', 'bill_date', 'due_date', 'currency',
        'amount', 'amount_paid', 'payment_method', 'payment_reference',
        'paid_date', 'description', 'attachment_path',
    ];

    protected function casts(): array
    {
        return [
            'bill_date'    => 'date',
            'due_date'     => 'date',
            'paid_date'    => 'date',
            'amount'       => 'decimal:2',
            'amount_paid'  => 'decimal:2',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function amountDue(): float
    {
        return max(0, (float) $this->amount - (float) $this->amount_paid);
    }

    public function isOverdue(): bool
    {
        return $this->due_date->isPast() && !in_array($this->status, ['paid', 'cancelled']);
    }
}
