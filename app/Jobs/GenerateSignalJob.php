<?php

namespace App\Jobs;

use App\Services\SignalService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateSignalJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public readonly string $symbol)
    {
        $this->onQueue('trading');
    }

    public function handle(SignalService $signals): void
    {
        $signals->generateSignal($this->symbol);
    }
}
