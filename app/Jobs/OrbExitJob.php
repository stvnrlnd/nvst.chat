<?php

namespace App\Jobs;

use App\Enums\SignalAction;
use App\Models\DailyPlay;
use App\Models\Position;
use App\Models\Signal;
use App\Services\MarketDataService;
use App\Services\TradingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class OrbExitJob implements ShouldQueue
{
    use Queueable;

    public $queue = 'trading';

    /**
     * Exit today's ORB position at the 20-minute mark.
     *
     * Finds the entered DailyPlay for today and submits a full sell.
     * This is a hard, unconditional exit — no signal strategy involved.
     */
    public function handle(MarketDataService $marketData, TradingService $trading): void
    {
        $play = DailyPlay::today()->entered()->first();

        if ($play === null) {
            Log::info('ORB exit: no entered play for today — nothing to close.');

            return;
        }

        $position = Position::where('symbol', $play->symbol)->first();

        if ($position === null || (float) $position->qty <= 0) {
            // Position already closed somehow (manual close, stop-loss, etc.)
            $play->update([
                'status' => 'exited',
                'exited_at' => now(),
                'skip_reason' => 'Position already closed before ORB exit window.',
            ]);

            Log::info("ORB exit: no open position for {$play->symbol} — marking play as exited.");

            return;
        }

        $currentPrice = $marketData->getCurrentPrice($play->symbol);

        $signal = Signal::create([
            'symbol' => $play->symbol,
            'action' => SignalAction::Sell,
            'price_at_signal' => $currentPrice,
            'reason' => '[ORB] Timed exit — 20-minute hold window elapsed.',
            'confidence' => 1.0,
            'executed' => false,
        ]);

        $trade = $trading->executeSignal($signal);

        $play->update([
            'status' => 'exited',
            'exit_signal_id' => $signal->id,
            'exited_at' => now(),
        ]);

        if ($trade !== null) {
            Log::info("ORB exit: sold {$play->symbol} (order #{$trade->alpaca_order_id}).");
        } else {
            Log::warning("ORB exit: executeSignal returned null for {$play->symbol} — position may still be open. Check manually.");
        }
    }
}
