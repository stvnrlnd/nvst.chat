<?php

namespace App\Models;

use Database\Factories\PositionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'symbol',
    'qty',
    'avg_entry_price',
    'current_price',
    'market_value',
    'unrealized_pl',
    'unrealized_plpc',
    'synced_at',
])]
class Position extends Model
{
    /** @use HasFactory<PositionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:8',
            'avg_entry_price' => 'decimal:8',
            'current_price' => 'decimal:8',
            'market_value' => 'decimal:8',
            'unrealized_pl' => 'decimal:8',
            'unrealized_plpc' => 'decimal:8',
            'synced_at' => 'datetime',
        ];
    }

    public function unrealizedPlPercent(): float
    {
        if (! $this->unrealized_plpc) {
            return 0.0;
        }

        return (float) $this->unrealized_plpc * 100;
    }

    public function isProfit(): bool
    {
        return ($this->unrealized_pl ?? 0) >= 0;
    }
}
