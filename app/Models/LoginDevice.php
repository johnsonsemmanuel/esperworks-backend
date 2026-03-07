<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoginDevice extends Model
{
    protected $fillable = [
        'user_id', 'ip_address', 'user_agent',
        'device_type', 'browser', 'browser_version',
        'platform', 'platform_version', 'device_name',
        'country', 'city', 'region',
        'latitude', 'longitude', 'last_active_at',
    ];

    protected function casts(): array
    {
        return [
            'last_active_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
