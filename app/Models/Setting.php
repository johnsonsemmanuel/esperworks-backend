<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    protected $casts = [
        'value' => 'array',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        $record = static::query()->where('key', $key)->first();

        if (!$record) {
            return $default;
        }

        // If the stored value is a scalar that was cast to array, return it directly
        $value = $record->value;
        return $value === null ? $default : $value;
    }

    public static function set(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }
}

