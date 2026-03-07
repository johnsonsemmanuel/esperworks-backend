<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecurringInvoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'client_id',
        'title',
        'description',
        'currency',
        'subtotal',
        'vat_rate',
        'vat_amount',
        'total',
        'frequency',
        'interval_count',
        'day_of_month',
        'start_date',
        'end_date',
        'next_invoice_date',
        'is_active',
        'max_invoices',
        'invoices_created',
        'last_invoice_id',
        'notes',
        'items_data', // JSON encoded invoice items
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'next_invoice_date' => 'date',
            'is_active' => 'boolean',
            'subtotal' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'items_data' => 'array',
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

    public function lastInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'last_invoice_id');
    }

    // Helper methods for frequency
    public function getFrequencyLabel(): string
    {
        return match($this->frequency) {
            'daily' => 'Daily',
            'weekly' => 'Weekly',
            'biweekly' => 'Bi-weekly',
            'monthly' => 'Monthly',
            'quarterly' => 'Quarterly',
            'yearly' => 'Yearly',
            default => 'Custom',
        };
    }

    public function getNextInvoiceDate(): ?\Carbon\Carbon
    {
        if (!$this->is_active || !$this->next_invoice_date) {
            return null;
        }

        // Check if we've reached the end date or max invoices
        if ($this->end_date && $this->next_invoice_date->greaterThan($this->end_date)) {
            return null;
        }

        if ($this->max_invoices && $this->invoices_created >= $this->max_invoices) {
            return null;
        }

        return $this->next_invoice_date;
    }

    public function calculateNextDate(): \Carbon\Carbon
    {
        $currentDate = $this->next_invoice_date ?? $this->start_date;

        return match($this->frequency) {
            'daily' => $currentDate->addDays($this->interval_count ?? 1),
            'weekly' => $currentDate->addWeeks($this->interval_count ?? 1),
            'biweekly' => $currentDate->addWeeks(2),
            'monthly' => $currentDate->addMonths($this->interval_count ?? 1),
            'quarterly' => $currentDate->addMonths(3 * ($this->interval_count ?? 1)),
            'yearly' => $currentDate->addYears($this->interval_count ?? 1),
            default => $currentDate->addMonth(),
        };
    }

    public function shouldDeactivate(): bool
    {
        // Check if we've reached max invoices
        if ($this->max_invoices && $this->invoices_created >= $this->max_invoices) {
            return true;
        }

        // Check if we've passed end date
        if ($this->end_date && now()->greaterThan($this->end_date)) {
            return true;
        }

        return false;
    }
}
