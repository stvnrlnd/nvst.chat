<?php

namespace App\Models;

use App\Enums\OrderType;
use App\Enums\TradeSide;
use App\Enums\TradeStatus;
use Database\Factories\TradeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[Fillable([
    'symbol',
    'side',
    'qty',
    'order_type',
    'status',
    'filled_avg_price',
    'filled_qty',
    'alpaca_order_id',
    'signal_id',
    'submitted_at',
    'filled_at',
    'is_day_trade',
])]
class Trade extends Model
{
    /** @use HasFactory<TradeFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'side' => TradeSide::class,
            'order_type' => OrderType::class,
            'status' => TradeStatus::class,
            'qty' => 'decimal:8',
            'filled_avg_price' => 'decimal:8',
            'filled_qty' => 'decimal:8',
            'submitted_at' => 'datetime',
            'filled_at' => 'datetime',
            'is_day_trade' => 'boolean',
        ];
    }

    /**
     * Count day trades in the last N business days (rolling PDT window).
     *
     * Business days are Mon–Fri. We walk back $days calendar days and let
     * the DB filter weekends out via the filled_at day-of-week.
     */
    public static function rollingDayTradeCount(int $businessDays = 5): int
    {
        // Look back enough calendar days to cover N business days (worst case: spans a weekend)
        $lookback = $businessDays + 4;
        $since = Carbon::now()->subDays($lookback)->startOfDay();

        return static::query()
            ->where('is_day_trade', true)
            ->where('status', TradeStatus::Filled)
            ->where('filled_at', '>=', $since)
            ->whereRaw('DAYOFWEEK(filled_at) NOT IN (1, 7)') // exclude Sun=1, Sat=7
            ->count();
    }

    /**
     * Check whether a sell trade for the given symbol would be a day trade
     * (i.e. we also have a filled buy for it today).
     */
    public static function wouldBeDayTrade(string $symbol): bool
    {
        return static::query()
            ->where('symbol', $symbol)
            ->where('side', TradeSide::Buy)
            ->where('status', TradeStatus::Filled)
            ->whereDate('filled_at', Carbon::today())
            ->exists();
    }

    public function signal(): BelongsTo
    {
        return $this->belongsTo(Signal::class);
    }

    public function totalValue(): float
    {
        if (! $this->filled_avg_price || ! $this->filled_qty) {
            return 0.0;
        }

        return (float) $this->filled_avg_price * (float) $this->filled_qty;
    }
}
