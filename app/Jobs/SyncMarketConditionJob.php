<?php

namespace App\Jobs;

use App\Models\MarketCondition;
use App\Services\AlpacaService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncMarketConditionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    /**
     * Fetch today's intraday bar for the macro index (default: SPY) and record
     * whether the session qualifies as bearish.
     *
     * Runs every 5 minutes during market hours alongside the trading cycle so
     * the bearish flag stays current as the day progresses.
     */
    public function handle(AlpacaService $alpaca): void
    {
        $symbol = config('alpaca.macro_symbol', 'SPY');
        $threshold = (float) config('alpaca.macro_bear_threshold', -1.5);

        try {
            // Fetch today's 1-day bar (returns today's open/close if market is open)
            $bars = $alpaca->getBars($symbol, 1, '1Day');
            $bar = $bars[0] ?? null;

            if (! $bar) {
                Log::warning("SyncMarketConditionJob: no bar data for {$symbol}.");

                return;
            }

            $open = (float) $bar['o'];
            $close = (float) $bar['c'];

            if ($open <= 0) {
                return;
            }

            $changePct = (($close - $open) / $open) * 100;
            $isBearish = $changePct <= $threshold;

            MarketCondition::updateOrCreate(
                ['date' => now()->toDateString()],
                [
                    'symbol' => $symbol,
                    'open' => $open,
                    'close' => $close,
                    'change_pct' => $changePct,
                    'is_bearish' => $isBearish,
                ],
            );

            Log::info("SyncMarketConditionJob: {$symbol} {$changePct}% — ".($isBearish ? 'bearish' : 'neutral/bullish').'.');
        } catch (\Throwable $e) {
            Log::warning("SyncMarketConditionJob: failed for {$symbol}: {$e->getMessage()}");
        }
    }
}
