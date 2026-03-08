<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

/**
 * Global scope trait that automatically filters queries by business_id
 * when a business route parameter is present. Prevents cross-tenant data leaks.
 *
 * Usage: Add `use BelongsToBusiness;` to any model that has a business_id column.
 */
trait BelongsToBusiness
{
    protected static function bootBelongsToBusiness(): void
    {
        static::addGlobalScope('business', function (Builder $query) {
            $businessParam = request()?->route('business');
            if (!$businessParam) {
                return;
            }

            $businessId = $businessParam instanceof \App\Models\Business
                ? $businessParam->id
                : (is_numeric($businessParam) ? (int) $businessParam : null);

            if ($businessId) {
                $query->where($query->getModel()->getTable() . '.business_id', $businessId);
            }
        });
    }
}
