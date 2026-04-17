<?php

namespace App\Jobs;

use App\Services\MarketDataService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class FetchMarketDataJob implements ShouldQueue
{
    use Queueable;

    public string $queue = 'sync';
    public int $tries = 3;

    public int $backoff = 30;

    public function handle(MarketDataService $marketData): void
    {
        $marketData->refreshPositionPrices();
    }
}
