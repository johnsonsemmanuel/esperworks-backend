<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'user_id', 'action', 'description',
        'model_type', 'model_id', 'data', 'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function log(string $action, ?string $description = null, ?Model $model = null, array $data = []): self
    {
        // Derive business_id from model, route, or authenticated user
        $businessId = null;
        if ($model && isset($model->business_id)) {
            $businessId = $model->business_id;
        } elseif ($model instanceof \App\Models\Business) {
            $businessId = $model->id;
        } elseif (request()->route('business')) {
            $business = request()->route('business');
            $businessId = $business instanceof \App\Models\Business ? $business->id : $business;
        }

        return self::create([
            'business_id' => $businessId,
            'user_id' => auth()->id(),
            'action' => $action,
            'description' => $description,
            'model_type' => $model ? get_class($model) : null,
            'model_id' => $model?->getKey(),
            'data' => $data ?: null,
            'ip_address' => request()->ip(),
        ]);
    }
}
