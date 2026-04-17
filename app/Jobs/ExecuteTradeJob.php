<?php

namespace App\Jobs;

use App\Models\Signal;
use App\Services\TradingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExecuteTradeJob implements ShouldQueue
{
    use Queueable;

    public $queue = 'trading';

    public int $tries = 2;

    public int $backoff = 30;

    public function __construct(public readonly Signal $signal) {}

    public function handle(TradingService $trading): void
    {
        $trading->executeSignal($this->signal);
    }
}
