<?php

namespace App\Jobs;

use App\Models\Stock;
use App\Services\AlpacaService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncAutoWatchlistJob implements ShouldQueue
{
    use Queueable;

    public $queue = 'sync';
    /**
     * Sync the automated watchlist from Alpaca's most-actives and top gainers.
     *
     * Flow:
     *   1. Pull top N symbols from most-actives and top gainers (deduplicated).
     *   2. Upsert each into the stocks table with source='auto', is_active=true, last_seen_at=now().
     *      Manual symbols with the same symbol are left untouched.
     *   3. Deactivate auto symbols not seen within auto_watchlist_stale_days.
     */
    public function handle(AlpacaService $alpaca): void
    {
        if (! config('alpaca.auto_watchlist_enabled')) {
            Log::info('Auto watchlist sync skipped — ALPACA_AUTO_WATCHLIST_ENABLED is false.');

            return;
        }

        $size = (int) config('alpaca.auto_watchlist_size', 50);
        $symbols = $this->fetchSymbols($alpaca, $size);

        if (empty($symbols)) {
            Log::warning('Auto watchlist: no symbols returned from Alpaca — skipping sync.');

            return;
        }

        $added = 0;
        $updated = 0;

        foreach ($symbols as $symbol) {
            // Never overwrite a manually-added stock — leave source/notes intact
            $existing = Stock::where('symbol', $symbol)->first();

            if ($existing !== null && $existing->isManual()) {
                // Touch last_seen_at so manual symbols still benefit from freshness tracking
                // but leave source as 'manual'
                $existing->update(['last_seen_at' => now()]);
                continue;
            }

            if ($existing !== null) {
                $existing->update(['is_active' => true, 'last_seen_at' => now()]);
                $updated++;
            } else {
                Stock::create([
                    'symbol' => $symbol,
                    'is_active' => true,
                    'source' => 'auto',
                    'last_seen_at' => now(),
                ]);
                $added++;
            }
        }

        // Deactivate stale auto symbols (not seen within stale_days)
        $staleDays = (int) config('alpaca.auto_watchlist_stale_days', 7);
        $deactivated = Stock::stale($staleDays)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        Log::info(sprintf(
            'Auto watchlist sync complete: %d added, %d updated, %d deactivated (stale > %d days).',
            $added,
            $updated,
            $deactivated,
            $staleDays,
        ));
    }

    /**
     * Fetch and deduplicate symbols from most-actives and top gainers.
     *
     * @return string[]
     */
    private function fetchSymbols(AlpacaService $alpaca, int $size): array
    {
        $symbols = [];

        try {
            $response = $alpaca->getMostActives($size);
            $symbols = array_merge($symbols, array_column($response['most_actives'] ?? [], 'symbol'));
        } catch (\Throwable $e) {
            Log::warning("Auto watchlist: getMostActives failed: {$e->getMessage()}");
        }

        try {
            $response = $alpaca->getTopMovers($size);
            $symbols = array_merge($symbols, array_column($response['gainers'] ?? [], 'symbol'));
        } catch (\Throwable $e) {
            Log::warning("Auto watchlist: getTopMovers failed: {$e->getMessage()}");
        }

        return array_values(array_unique($symbols));
    }
}
