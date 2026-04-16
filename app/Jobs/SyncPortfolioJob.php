<?php

namespace App\Jobs;

use App\Services\TradingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncPortfolioJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function handle(TradingService $trading): void
    {
        $trading->syncPortfolio();
        $trading->syncPendingOrders();
    }
}
