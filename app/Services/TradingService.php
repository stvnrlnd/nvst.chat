<?php

namespace App\Services;

use App\Enums\OrderType;
use App\Enums\SignalAction;
use App\Enums\TradeSide;
use App\Enums\TradeStatus;
use App\Models\Position;
use App\Models\Signal;
use App\Models\Trade;
use Illuminate\Support\Facades\Log;

class TradingService
{
    public function __construct(private readonly AlpacaService $alpaca) {}

    /**
     * Sync local positions table from Alpaca.
     */
    public function syncPortfolio(): void
    {
        $alpacaPositions = $this->alpaca->getPositions();
        $seen = [];

        foreach ($alpacaPositions as $ap) {
            $symbol = $ap['symbol'];
            $seen[] = $symbol;

            Position::updateOrCreate(
                ['symbol' => $symbol],
                [
                    'qty' => $ap['qty'],
                    'avg_entry_price' => $ap['avg_entry_price'],
                    'current_price' => $ap['current_price'],
                    'market_value' => $ap['market_value'],
                    'unrealized_pl' => $ap['unrealized_pl'],
                    'unrealized_plpc' => $ap['unrealized_plpc'],
                    'synced_at' => now(),
                ],
            );
        }

        // Remove positions closed on Alpaca's side
        if (! empty($seen)) {
            Position::whereNotIn('symbol', $seen)->delete();
        } else {
            Position::query()->delete();
        }
    }

    /**
     * Execute a signal as an order on Alpaca.
     *
     * Returns the created Trade, or null if skipped.
     */
    public function executeSignal(Signal $signal): ?Trade
    {
        if (! config('alpaca.trading_enabled')) {
            Log::info("Trading disabled — signal #{$signal->id} for {$signal->symbol} not executed.");

            return null;
        }

        if ($signal->action === SignalAction::Hold) {
            return null;
        }

        $account = $this->alpaca->getAccount();
        $buyingPower = (float) ($account['buying_power'] ?? 0);
        $portfolioValue = (float) ($account['portfolio_value'] ?? 0);

        if ($portfolioValue <= 0) {
            Log::warning('Portfolio value is zero — skipping trade execution.');

            return null;
        }

        $currentPrice = (float) ($signal->price_at_signal ?? 0);

        if ($currentPrice <= 0) {
            Log::warning("Signal #{$signal->id} has no price — cannot size position.");

            return null;
        }

        $side = $signal->action === SignalAction::Buy ? TradeSide::Buy : TradeSide::Sell;

        // For sells, use the existing position qty
        if ($side === TradeSide::Sell) {
            $position = Position::where('symbol', $signal->symbol)->first();

            if (! $position || (float) $position->qty <= 0) {
                Log::info("No position in {$signal->symbol} to sell.");
                $signal->update(['executed' => true]);

                return null;
            }

            $qty = (float) $position->qty;
        } else {
            // Size the buy: position_size_pct of portfolio, capped at buying power
            $targetValue = $portfolioValue * (float) config('alpaca.position_size_pct', 0.05);
            $maxValue = $portfolioValue * (float) config('alpaca.max_position_pct', 0.20);

            // Check existing position value
            $existingPosition = Position::where('symbol', $signal->symbol)->first();
            $existingValue = $existingPosition ? (float) $existingPosition->market_value : 0;

            if ($existingValue >= $maxValue) {
                Log::info("Already at max position size for {$signal->symbol} — skipping buy.");
                $signal->update(['executed' => true]);

                return null;
            }

            $deployValue = min($targetValue, $maxValue - $existingValue, $buyingPower);

            if ($deployValue < 1.0) {
                Log::warning("Insufficient buying power to execute buy for {$signal->symbol}.");

                return null;
            }

            // Fractional shares — Alpaca accepts notional orders
            $qty = round($deployValue / $currentPrice, 6);
        }

        if ($qty <= 0) {
            return null;
        }

        try {
            $order = $this->alpaca->submitOrder([
                'symbol' => $signal->symbol,
                'qty' => $qty,
                'side' => $side->value,
                'type' => OrderType::Market->value,
                'time_in_force' => 'day',
            ]);

            $trade = Trade::create([
                'symbol' => $signal->symbol,
                'side' => $side,
                'qty' => $qty,
                'order_type' => OrderType::Market,
                'status' => TradeStatus::Pending,
                'alpaca_order_id' => $order['id'] ?? null,
                'signal_id' => $signal->id,
                'submitted_at' => now(),
            ]);

            $signal->update(['executed' => true]);

            Log::info("Order submitted for {$signal->symbol}: {$side->value} {$qty} shares (order #{$order['id']})");

            return $trade;
        } catch (\Throwable $e) {
            Log::error("Failed to submit order for {$signal->symbol}: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * Poll pending orders and update their status.
     */
    public function syncPendingOrders(): void
    {
        $pendingTrades = Trade::where('status', TradeStatus::Pending)
            ->whereNotNull('alpaca_order_id')
            ->get();

        foreach ($pendingTrades as $trade) {
            try {
                $order = $this->alpaca->getOrder($trade->alpaca_order_id);
                $status = TradeStatus::tryFrom($order['status']) ?? TradeStatus::Pending;

                $updates = ['status' => $status];

                if ($status === TradeStatus::Filled) {
                    $updates['filled_avg_price'] = $order['filled_avg_price'] ?? null;
                    $updates['filled_qty'] = $order['filled_qty'] ?? null;
                    $updates['filled_at'] = now();
                }

                $trade->update($updates);
            } catch (\Throwable $e) {
                Log::warning("Could not sync order {$trade->alpaca_order_id}: {$e->getMessage()}");
            }
        }
    }
}
