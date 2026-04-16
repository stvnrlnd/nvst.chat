<?php

namespace App\Console\Commands;

use App\Services\ScreenerService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('screener:run')]
#[Description('Score and rank active watchlist symbols, saving top candidates for the next trading day')]
class RunScreener extends Command
{
    public function handle(ScreenerService $screener): int
    {
        $this->info('Running screener...');

        $candidates = $screener->run();

        if (empty($candidates)) {
            $this->warn('No candidates scored. Check that active symbols exist in the watchlist.');

            return self::SUCCESS;
        }

        $qualified = array_filter($candidates, fn ($c) => ! $c->disqualified);
        $disqualified = array_filter($candidates, fn ($c) => $c->disqualified);

        $this->newLine();
        $this->line('<fg=green>Qualified candidates:</>');

        foreach ($qualified as $c) {
            $this->line(sprintf(
                '  %s  score=%.1f  price=$%.2f  atr=%.2f%%  sma5=%.2f  sma20=%.2f  up_days=%d/5',
                str_pad($c->symbol, 6),
                $c->score,
                $c->price ?? 0,
                $c->atr_pct ?? 0,
                $c->sma5 ?? 0,
                $c->sma20 ?? 0,
                $c->up_days ?? 0,
            ));
        }

        if (! empty($disqualified)) {
            $this->newLine();
            $this->line('<fg=yellow>Disqualified:</>');

            foreach ($disqualified as $c) {
                $this->line(sprintf('  %s  — %s', str_pad($c->symbol, 6), $c->disqualified_reason));
            }
        }

        $this->newLine();
        $this->info(sprintf('%d qualified, %d disqualified.', count($qualified), count($disqualified)));

        return self::SUCCESS;
    }
}
