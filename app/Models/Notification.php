<?php

namespace App\Models;

use App\Events\NotificationCreated;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'user_id', 'business_id', 'type', 'title', 'message', 'data', 'link', 'read_at',
    ];

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::creating(function (Notification $notification) {
            if (empty($notification->id)) {
                $notification->id = (string) \Illuminate\Support\Str::uuid();
            }
        });

        static::created(function (Notification $notification) {
            try {
                NotificationCreated::dispatch($notification);
            } catch (\Throwable) {
                // Never let a broadcast failure break notification creation
            }
        });
    }

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
