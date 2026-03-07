<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'invoice_id', 'payment_id', 'amount', 'reason', 'method',
        'reference', 'status', 'processed_at', 'processed_by', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'processed_at' => 'datetime',
            'processed_by' => 'integer',
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

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'processed_by');
    }

    /**
     * Status constants
     */
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_CANCELLED = 'cancelled';

    /**
     * Get all available statuses
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_PENDING => 'Pending',
            self::STATUS_PROCESSING => 'Processing',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_FAILED => 'Failed',
            self::STATUS_CANCELLED => 'Cancelled',
        ];
    }

    /**
     * Check if refund can be processed
     */
    public function canBeProcessed(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if refund is completed
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Mark refund as processing
     */
    public function markAsProcessing(int $processedBy = null): void
    {
        $this->update([
            'status' => self::STATUS_PROCESSING,
            'processed_at' => now(),
            'processed_by' => $processedBy,
        ]);
    }

    /**
     * Mark refund as completed
     */
    public function markAsCompleted(int $processedBy = null): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'processed_at' => now(),
            'processed_by' => $processedBy,
        ]);
    }

    /**
     * Mark refund as failed
     */
    public function markAsFailed(string $reason = null, int $processedBy = null): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'processed_at' => now(),
            'processed_by' => $processedBy,
            'notes' => $reason,
        ]);
    }

    /**
     * Cancel refund
     */
    public function cancel(int $processedBy = null): void
    {
        $this->update([
            'status' => self::STATUS_CANCELLED,
            'processed_at' => now(),
            'processed_by' => $processedBy,
            'notes' => 'Refund cancelled by user',
        ]);
    }
}
