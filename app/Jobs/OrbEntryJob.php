<?php

namespace App\Jobs;

use App\Enums\SignalAction;
use App\Models\DailyPlay;
use App\Models\MarketCondition;
use App\Models\ScreenerCandidate;
use App\Models\Signal;
use App\Services\AlpacaService;
use App\Services\MarketDataService;
use App\Services\TradingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class OrbEntryJob implements ShouldQueue
{
    use Queueable;

    public ?string $queue = 'trading';

    /**
     * Enter the day's ORB position.
     *
     * Reads the top-scored screener candidate for today, runs pre-flight checks,
     * then fires a Buy signal through the normal TradingService flow.
     * A DailyPlay record is always created — either 'entered' or 'skipped'.
     */
    public function handle(
        AlpacaService $alpaca,
        MarketDataService $marketData,
        TradingService $trading,
    ): void {
        // Only one ORB play per day — safe to re-dispatch if the scheduler fires twice
        if (DailyPlay::today()->exists()) {
            Log::info('ORB entry: daily play already exists for today — skipping.');

            return;
        }

        if (! config('alpaca.trading_enabled')) {
            $this->skip('Trading is disabled (ALPACA_TRADING_ENABLED=false).');

            return;
        }

        if (! $alpaca->isMarketOpen()) {
            $this->skip('Market is not open.');

            return;
        }

        // Pick the top qualified candidate from last night's screener run
        $candidate = ScreenerCandidate::forDate(today()->toDateString())->first();

        if ($candidate === null) {
            $this->skip('No qualified screener candidates for today.');

            return;
        }

        // Suppress buys on bearish macro days
        if (MarketCondition::isTodayBearish()) {
            $macro = config('alpaca.macro_symbol', 'SPY');
            $this->skip("Macro filter: {$macro} is bearish today.");

            return;
        }

        // Get current price for position sizing
        $currentPrice = $marketData->getCurrentPrice($candidate->symbol);

        if ($currentPrice === null || $currentPrice <= 0) {
            $this->skip("Could not fetch current price for {$candidate->symbol}.");

            return;
        }

        // Create a Buy signal and run it through the standard execution path
        // (PDT, earnings blackout, buying-power checks all happen inside executeSignal)
        $signal = Signal::create([
            'symbol' => $candidate->symbol,
            'action' => SignalAction::Buy,
            'price_at_signal' => $currentPrice,
            'reason' => "[ORB] Screener pick for {$candidate->screened_date->toDateString()} — score {$candidate->score}",
            'confidence' => round($candidate->score / 100, 2),
            'executed' => false,
        ]);

        $trade = $trading->executeSignal($signal);

        if ($trade === null) {
            DailyPlay::create([
                'symbol' => $candidate->symbol,
                'date' => today(),
                'status' => 'skipped',
                'skip_reason' => 'Trade blocked by execution checks (PDT, earnings, buying power, etc.).',
                'entry_signal_id' => $signal->id,
            ]);

            Log::info("ORB entry: trade for {$candidate->symbol} was blocked — play marked skipped.");

            return;
        }

        DailyPlay::create([
            'symbol' => $candidate->symbol,
            'date' => today(),
            'status' => 'entered',
            'entry_signal_id' => $signal->id,
            'entered_at' => now(),
        ]);

        Log::info("ORB entry: entered {$candidate->symbol} at \${$currentPrice} (score {$candidate->score}).");
    }

    private function skip(string $reason): void
    {
        Log::info("ORB entry skipped: {$reason}");

        // Only create the play record if we've already confirmed one doesn't exist
        // (callers that reach skip() have already verified this via the early-return check)
        DailyPlay::create([
            'symbol' => 'N/A',
            'date' => today(),
            'status' => 'skipped',
            'skip_reason' => $reason,
        ]);
    }
}
