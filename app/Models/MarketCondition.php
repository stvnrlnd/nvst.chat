<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

#[Fillable(['symbol', 'date', 'open', 'close', 'change_pct', 'is_bearish'])]
class MarketCondition extends Model
{
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'open' => 'decimal:8',
            'close' => 'decimal:8',
            'change_pct' => 'decimal:4',
            'is_bearish' => 'boolean',
        ];
    }

    /**
     * Whether today's market condition is bearish.
     *
     * Returns false (not bearish / allow trading) if no data exists for today,
     * so a missing refresh doesn't accidentally block all trades.
     */
    public static function isTodayBearish(string $symbol = 'SPY'): bool
    {
        return static::query()
            ->where('symbol', $symbol)
            ->whereDate('date', Carbon::today())
            ->value('is_bearish') ?? false;
    }
}
