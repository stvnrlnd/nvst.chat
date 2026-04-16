<?php

namespace App\Services;

use App\Enums\OrderType;
use App\Enums\SignalAction;
use App\Enums\TradeSide;
use App\Enums\TradeStatus;
use App\Jobs\ExecuteTradeJob;
use App\Models\EarningsEvent;
use App\Models\MarketCondition;
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

        $account = $this->alpaca->getAccountStatus();
        $buyingPower = $account->buyingPower;
        $portfolioValue = $account->portfolioValue;

        if ($portfolioValue <= 0) {
            Log::warning('Portfolio value is zero — skipping trade execution.');

            return null;
        }

        // Earnings blackout: skip trades within 2 days before / 1 day after earnings
        if (EarningsEvent::isInBlackout($signal->symbol)) {
            $next = EarningsEvent::nextEarningsDate($signal->symbol);
            $dateLabel = $next ? $next->toDateString() : 'soon';
            Log::info("Earnings blackout for {$signal->symbol} (report {$dateLabel}) — skipping signal #{$signal->id}.");
            $signal->update(['executed' => true]);

            return null;
        }

        // Macro filter: suppress BUY signals on bearish market days
        if ($signal->action === SignalAction::Buy && MarketCondition::isTodayBearish()) {
            $macro = config('alpaca.macro_symbol', 'SPY');
            Log::info("Macro filter: {$macro} is down beyond threshold today — suppressing BUY for {$signal->symbol}.");
            $signal->update(['executed' => true]);

            return null;
        }

        // PDT check 1: account is flagged and under the $25k equity threshold
        if ($account->isPdtRestricted()) {
            Log::warning("PDT restriction active — account flagged as pattern day trader with equity below \$25,000. Skipping {$signal->symbol}.");
            $signal->update(['executed' => true]);

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

            // PDT check 2: would this sell create a day trade that exceeds the rolling limit?
            if (Trade::wouldBeDayTrade($signal->symbol)) {
                $rolling = Trade::rollingDayTradeCount();

                if ($rolling >= 3) {
                    Log::warning("PDT limit reached ({$rolling}/3 day trades in rolling 5-day window) — holding {$signal->symbol} sell to avoid flagging account.");
                    $signal->update(['executed' => true]);

                    return null;
                }

                if ($rolling >= 2) {
                    Log::warning("PDT warning: executing this sell will use day trade {$rolling}/3 for {$signal->symbol}. One remaining after this.");
                }
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
     * Force-sell any positions that have breached the stop-loss threshold.
     *
     * Creates a SELL signal and dispatches ExecuteTradeJob for each breached position
     * so the trade flows through the normal execution path (PDT checks, logging, etc).
     *
     * Returns the number of stop-loss triggers fired.
     */
    public function checkStopLosses(): int
    {
        $threshold = config('alpaca.stop_loss_pct');

        if ($threshold === null) {
            return 0;
        }

        $threshold = (float) $threshold;
        $triggered = 0;

        Position::all()->each(function (Position $position) use ($threshold, &$triggered): void {
            $plpc = (float) $position->unrealized_plpc;

            if ($plpc >= $threshold) {
                return;
            }

            $pct = number_format($plpc * 100, 2);
            Log::warning("Stop-loss triggered for {$position->symbol}: unrealized P&L {$pct}% (threshold: ".number_format($threshold * 100, 2).'%).');

            $signal = Signal::create([
                'symbol' => $position->symbol,
                'action' => SignalAction::Sell,
                'price_at_signal' => (float) $position->current_price,
                'reason' => "Stop-loss: position at {$pct}% (threshold ".number_format($threshold * 100, 2).'%).',
                'confidence' => 1.0,
                'executed' => false,
            ]);

            ExecuteTradeJob::dispatch($signal);
            $triggered++;
        });

        return $triggered;
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

                    // Mark as a day trade if this is a sell and we also bought today
                    if ($trade->side === TradeSide::Sell && Trade::wouldBeDayTrade($trade->symbol)) {
                        $updates['is_day_trade'] = true;
                    }
                }

                $trade->update($updates);
            } catch (\Throwable $e) {
                Log::warning("Could not sync order {$trade->alpaca_order_id}: {$e->getMessage()}");
            }
        }
    }
}
