<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditNote extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'client_id',
        'invoice_id',
        'credit_note_number',
        'status',
        'issue_date',
        'currency',
        'subtotal',
        'vat_amount',
        'total',
        'amount_applied',
        'reason',
        'notes',
        'items',
    ];

    protected function casts(): array
    {
        return [
            'issue_date'     => 'date',
            'subtotal'       => 'decimal:2',
            'vat_amount'     => 'decimal:2',
            'total'          => 'decimal:2',
            'amount_applied' => 'decimal:2',
            'items'          => 'array',
        ];
    }

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
    public function client(): BelongsTo   { return $this->belongsTo(Client::class); }
    public function invoice(): BelongsTo  { return $this->belongsTo(Invoice::class); }

    public function amountRemaining(): float
    {
        return max(0, (float) $this->total - (float) $this->amount_applied);
    }
}
