<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use HasFactory, SoftDeletes, BelongsToBusiness;

    protected $hidden = ['signing_token'];

    protected $appends = ['source_reference'];

    protected $fillable = [
        'business_id',
        'client_id',
        'contract_id',
        'invoice_template_id',
        'invoice_number',
        'status',
        'issue_date',
        'due_date',
        'currency',
        'subtotal',
        'vat_rate',
        'vat_amount',
        'nhil_rate',
        'nhil_amount',
        'getfund_rate',
        'getfund_amount',
        'covid_levy_rate',
        'covid_levy_amount',
        'discount_rate',
        'discount_amount',
        'use_ghana_tax',
        'total',
        'amount_paid',
        'notes',
        'payment_method',
        'signature_required',
        'business_signature_name',
        'business_signature_image',
        'business_signed_at',
        'client_signature_required',
        'client_signature_name',
        'client_signature_image',
        'client_signed_at',
        'pdf_path',
        'sent_at',
        'viewed_at',
        'paid_at',
        'signing_token',
        'token_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'nhil_rate' => 'decimal:2',
            'nhil_amount' => 'decimal:2',
            'getfund_rate' => 'decimal:2',
            'getfund_amount' => 'decimal:2',
            'covid_levy_rate' => 'decimal:2',
            'covid_levy_amount' => 'decimal:2',
            'discount_rate' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'use_ghana_tax' => 'boolean',
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'signature_required' => 'boolean',
            'client_signature_required' => 'boolean',
            'business_signed_at' => 'datetime',
            'client_signed_at' => 'datetime',
            'sent_at' => 'datetime',
            'viewed_at' => 'datetime',
            'paid_at' => 'datetime',
            'token_expires_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort_order');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(InvoiceTemplate::class);
    }

    public function calculateTotals(): void
    {
        $subtotal = $this->items->sum('amount');

        // Apply discount first
        $discountAmount = $subtotal * ($this->discount_rate / 100);
        $taxableAmount  = $subtotal - $discountAmount;

        if ($this->use_ghana_tax) {
            // Ghana VAT is applied on the taxable amount
            $vatAmount      = $taxableAmount * ($this->vat_rate / 100);
            $nhilAmount     = $taxableAmount * ($this->nhil_rate / 100);
            $getfundAmount  = $taxableAmount * ($this->getfund_rate / 100);
            $covidAmount    = $taxableAmount * ($this->covid_levy_rate / 100);
            $totalTax       = $vatAmount + $nhilAmount + $getfundAmount + $covidAmount;
        } else {
            $vatAmount     = $taxableAmount * ($this->vat_rate / 100);
            $nhilAmount    = 0;
            $getfundAmount = 0;
            $covidAmount   = 0;
            $totalTax      = $vatAmount;
        }

        $this->update([
            'subtotal'          => $subtotal,
            'discount_amount'   => $discountAmount,
            'vat_amount'        => $vatAmount,
            'nhil_amount'       => $nhilAmount,
            'getfund_amount'    => $getfundAmount,
            'covid_levy_amount' => $covidAmount,
            'total'             => $taxableAmount + $totalTax,
        ]);
    }

    public function isOverdue(): bool
    {
        return $this->due_date->isPast() && !in_array($this->status, ['paid', 'cancelled']);
    }

    public function isPartiallyPaid(): bool
    {
        return $this->amount_paid > 0 && $this->amount_paid < ($this->total - 0.01); // Allow for rounding
    }

    public function amountDue(): float
    {
        return max(0, (float) $this->total - (float) $this->amount_paid);
    }

    public function getPaymentStatus(): string
    {
        if ($this->isFullyPaid()) {
            return 'paid';
        } elseif ($this->isPartiallyPaid()) {
            return 'partial';
        } elseif ($this->status === 'overdue') {
            return 'overdue';
        } elseif (in_array($this->status, ['sent', 'viewed'])) {
            return 'unpaid';
        }
        
        return $this->status;
    }

    public function updatePaymentStatus(): void
    {
        $totalPaid = $this->payments()->where('status', 'success')->sum('amount');
        $newStatus = $this->status;
        
        if ($totalPaid >= ($this->total - 0.01)) {
            $newStatus = 'paid';
            $totalPaid = $this->total; // Prevent overpayment
        } elseif ($totalPaid > 0) {
            $newStatus = 'partial';
        }
        
        if ($newStatus !== $this->status) {
            $oldStatus = $this->status;
            $this->status = $newStatus;
            $this->amount_paid = $totalPaid;
            
            // Set timestamps appropriately
            if ($newStatus === 'paid' && !$this->paid_at) {
                $this->paid_at = now();
            }
            
            // Log status change
            ActivityLog::log('invoice.status_changed', 
                "Invoice {$this->invoice_number} status changed from {$oldStatus} to {$newStatus}", 
                $this
            );
        }
    }

    /** Human-readable source for invoices generated from contract/proposal (e.g. "Generated from Contract #CTR-0042"). */
    public function getSourceReferenceAttribute(): ?string
    {
        if (!$this->contract_id) {
            return null;
        }
        $contract = $this->relationLoaded('contract') ? $this->contract : $this->contract()->first();
        if (!$contract) {
            return null;
        }
        $ref = $contract->contract_number ?? ('#' . $contract->id);
        return $contract->type === 'proposal'
            ? "Generated from Proposal {$ref}"
            : "Generated from Contract {$ref}";
    }

    public function isFullyPaid(): bool
    {
        return $this->amount_paid >= ($this->total - 0.01); // Allow for rounding
    }

    public function markAsPaid(): void
    {
        // Use actual sum of successful payments if available, otherwise fall back to total
        $totalPaid = $this->payments()->where('status', 'success')->sum('amount');
        $this->update([
            'status' => 'paid',
            'paid_at' => $this->paid_at ?? now(),
            'amount_paid' => min($totalPaid, $this->total), // Prevent overpayment
        ]);
    }

    public function isTokenExpired(): bool
    {
        return $this->token_expires_at && now()->isAfter($this->token_expires_at);
    }

    public function extendTokenExpiry(int $days = 30): void
    {
        $this->update([
            'token_expires_at' => now()->addDays($days),
        ]);
    }

    public function revokeToken(): void
    {
        $this->update([
            'token_expires_at' => now()->subDay(), // Expire immediately
        ]);
    }
}
