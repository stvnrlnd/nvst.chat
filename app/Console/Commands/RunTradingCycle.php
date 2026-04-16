<?php

namespace App\Console\Commands;

use App\Enums\SignalAction;
use App\Jobs\ExecuteTradeJob;
use App\Models\Stock;
use App\Services\AlpacaService;
use App\Services\SignalService;
use App\Services\TradingService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('trading:run')]
#[Description('Run one full trading cycle: sync portfolio → generate signals → execute trades')]
class RunTradingCycle extends Command
{
    public function handle(
        AlpacaService $alpaca,
        TradingService $trading,
        SignalService $signals,
    ): int {
        // Bail if market is closed
        if (! $alpaca->isMarketOpen()) {
            $this->info('Market is closed — skipping cycle.');

            return self::SUCCESS;
        }

        $this->info('Market is open. Starting trading cycle...');

        // Step 1: Sync portfolio from Alpaca
        $this->line('  → Syncing portfolio...');
        $trading->syncPortfolio();
        $trading->syncPendingOrders();

        // Step 1b: Check stop-losses before generating new signals
        $stopLosses = $trading->checkStopLosses();

        if ($stopLosses > 0) {
            $this->warn("  → Stop-loss triggered for {$stopLosses} position(s).");
        }

        // Step 2: Generate signals for all active stocks
        $stocks = Stock::active()->get();

        if ($stocks->isEmpty()) {
            $this->warn('  → No active stocks in watchlist. Add symbols via the dashboard.');

            return self::SUCCESS;
        }

        $this->line("  → Generating signals for {$stocks->count()} symbol(s)...");

        foreach ($stocks as $stock) {
            $signal = $signals->generateSignal($stock->symbol);
            $actionLabel = $signal->action->label();
            $this->line("     {$stock->symbol}: {$actionLabel} — {$signal->reason}");

            if ($signal->action !== SignalAction::Hold) {
                ExecuteTradeJob::dispatch($signal);
            }
        }

        $this->info('Cycle complete.');

        return self::SUCCESS;
    }
}
