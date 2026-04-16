<?php

namespace App\Models;

use Database\Factories\StockFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['symbol', 'name', 'is_active', 'notes'])]
class Stock extends Model
{
    /** @use HasFactory<StockFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function signals(): HasMany
    {
        return $this->hasMany(Signal::class, 'symbol', 'symbol');
    }

    public function trades(): HasMany
    {
        return $this->hasMany(Trade::class, 'symbol', 'symbol');
    }

    public function scopeActive($query): void
    {
        $query->where('is_active', true);
    }
}
