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
        ];
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
