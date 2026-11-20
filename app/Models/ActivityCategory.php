<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'exempt_short_duration',
    'is_active',
])]
class ActivityCategory extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'exempt_short_duration' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<SalesVisit, $this> */
    public function salesVisits(): HasMany
    {
        return $this->hasMany(SalesVisit::class, 'activity_category_id');
    }
}
