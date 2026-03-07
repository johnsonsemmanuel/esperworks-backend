<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationFailure extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'data',
        'error_message',
        'attempt',
        'next_retry_at',
        'status',
        'resolved_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'next_retry_at' => 'datetime',
            'resolved_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope for pending failures
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope for failures ready to retry
     */
    public function scopeReadyToRetry($query)
    {
        return $query->where('status', 'pending')
            ->where('next_retry_at', '<=', now());
    }

    /**
     * Scope for resolved failures
     */
    public function scopeResolved($query)
    {
        return $query->where('status', 'resolved');
    }

    /**
     * Scope for failed (permanent) failures
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }
}
