<?php

namespace App\Jobs;

use App\Models\EarningsEvent;
use App\Models\Stock;
use App\Services\PolygonService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncEarningsCalendarJob implements ShouldQueue
{
    use Queueable;

    public string $queue = 'sync';
    public int $tries = 3;

    public int $backoff = 60;

    /**
     * Sync earnings dates for all active watchlist symbols from Polygon.
     *
     * Designed to run once daily (scheduled in routes/console.php).
     * If Polygon key is not configured, the job skips silently so it doesn't
     * block the trading cycle.
     */
    public function handle(PolygonService $polygon): void
    {
        if (! config('services.polygon.key')) {
            Log::info('SyncEarningsCalendarJob: POLYGON_API_KEY not set, skipping.');

            return;
        }

        $symbols = Stock::active()->pluck('symbol');

        foreach ($symbols as $symbol) {
            try {
                $dates = $polygon->getEarningsDates($symbol);

                foreach ($dates as $date) {
                    EarningsEvent::updateOrCreate(
                        ['symbol' => $symbol, 'report_date' => $date],
                        ['refreshed_at' => now()],
                    );
                }

                Log::info("SyncEarningsCalendarJob: synced {$symbol} — ".count($dates).' earnings date(s).');
            } catch (\Throwable $e) {
                Log::warning("SyncEarningsCalendarJob: failed for {$symbol}: {$e->getMessage()}");
            }
        }
    }
}
