<?php

namespace App\Services;

use App\Models\EarningsEvent;
use App\Models\ScreenerCandidate;
use App\Models\Stock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class ScreenerService
{
    public function __construct(
        private readonly AlpacaService $alpaca,
        private readonly MarketDataService $marketData,
    ) {}

    /**
     * Score all candidate symbols and persist the results for the given date.
     *
     * Candidate pool:
     *   - Always includes active watchlist symbols.
     *   - When screener_use_universe is true, also includes the top N most-active
     *     symbols from Alpaca, giving the screener a hands-off broad universe.
     *
     * Scoring breakdown (max 100):
     *   Momentum   (0–30 pts) — SMA5 vs SMA20 direction and separation
     *   Volatility (0–40 pts) — ATR% in the ideal 2–4% range for 20-min plays
     *   Trend      (0–30 pts) — up-days in the last 5 bars (6 pts each)
     *
     * Disqualifiers (row saved with disqualified=true):
     *   - Price below screener_min_price
     *   - ATR% outside screener_min/max_atr_pct
     *   - Earnings event within 2 days
     *   - Fewer than 22 bars (insufficient history)
     *
     * @return ScreenerCandidate[]  All candidates sorted by score descending.
     */
    public function run(?Carbon $date = null): array
    {
        $date ??= Carbon::today();
        $dateStr = $date->toDateString();

        $symbols = $this->buildSymbolList();

        if (empty($symbols)) {
            Log::warning('Screener: no symbols to score. Add stocks to the watchlist or enable universe mode.');

            return [];
        }

        // Clear previous run for this date so re-runs are idempotent
        ScreenerCandidate::where('screened_date', $dateStr)->delete();

        // Fetch all bars in a single bulk API call
        $barsBySymbol = $this->fetchBarsForSymbols($symbols);

        $results = [];

        foreach ($symbols as $symbol) {
            $bars = $barsBySymbol[$symbol] ?? [];
            $candidate = $this->scoreSymbol($symbol, $dateStr, $bars);

            if ($candidate !== null) {
                $results[] = $candidate;
            }
        }

        usort($results, fn ($a, $b) => $b->score <=> $a->score);

        $qualified = count(array_filter($results, fn ($c) => ! $c->disqualified));

        Log::info(sprintf(
            'Screener complete for %s: %d qualified, %d disqualified (universe_mode=%s).',
            $dateStr,
            $qualified,
            count($results) - $qualified,
            config('alpaca.screener_use_universe') ? 'on' : 'off',
        ));

        return $results;
    }

    // ── Symbol list ───────────────────────────────────────────────────────────

    /**
     * Build the deduplicated list of symbols to score.
     *
     * @return string[]
     */
    private function buildSymbolList(): array
    {
        $symbols = Stock::active()->pluck('symbol')->all();

        if (config('alpaca.screener_use_universe')) {
            $universeSize = (int) config('alpaca.screener_universe_size', 50);
            $universeSymbols = $this->fetchMostActiveSymbols($universeSize);
            $symbols = array_values(array_unique(array_merge($symbols, $universeSymbols)));
        }

        return $symbols;
    }

    /**
     * Fetch the most-active symbol list from Alpaca.
     * Returns an empty array on failure so the screener degrades gracefully.
     *
     * @return string[]
     */
    private function fetchMostActiveSymbols(int $top): array
    {
        try {
            $response = $this->alpaca->getMostActives($top);

            return array_column($response['most_actives'] ?? [], 'symbol');
        } catch (\Throwable $e) {
            Log::warning("Screener: could not fetch most-actives universe: {$e->getMessage()}");

            return [];
        }
    }

    // ── Bar fetching ──────────────────────────────────────────────────────────

    /**
     * Fetch 30-day bars for all symbols in a single bulk API call.
     * Falls back to an empty array for any symbol that returns no data.
     *
     * @param  string[]  $symbols
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function fetchBarsForSymbols(array $symbols): array
    {
        try {
            return $this->alpaca->getBarsBulk($symbols, 30);
        } catch (\Throwable $e) {
            Log::warning("Screener: bulk bar fetch failed ({$e->getMessage()}). Falling back to per-symbol fetching.");

            return $this->fetchBarsFallback($symbols);
        }
    }

    /**
     * Per-symbol fallback when the bulk endpoint fails.
     *
     * @param  string[]  $symbols
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function fetchBarsFallback(array $symbols): array
    {
        $result = [];

        foreach ($symbols as $symbol) {
            try {
                $result[$symbol] = $this->alpaca->getBars($symbol, 30);
            } catch (\Throwable $e) {
                Log::warning("Screener: could not fetch bars for {$symbol}: {$e->getMessage()}");
            }
        }

        return $result;
    }

    // ── Scoring ───────────────────────────────────────────────────────────────

    /**
     * Score a single symbol from pre-fetched bars and persist the result.
     *
     * @param  array<int, array<string, mixed>>  $bars
     */
    private function scoreSymbol(string $symbol, string $dateStr, array $bars): ?ScreenerCandidate
    {
        if (empty($bars)) {
            // No bars returned — could be a bad symbol or API gap; skip silently
            return null;
        }

        if (count($bars) < 22) {
            return $this->save($symbol, $dateStr, 0, [], 'Insufficient price history (< 22 bars).');
        }

        $closes = array_map(fn ($b) => (float) $b['c'], $bars);
        $currentPrice = end($closes);
        $sma5 = $this->marketData->sma($closes, 5);
        $sma20 = $this->marketData->sma($closes, 20);
        $atr = $this->marketData->atr($bars, 14);
        $atrPct = ($atr !== null && $currentPrice > 0) ? ($atr / $currentPrice) * 100 : null;
        $upDays = $this->countUpDays($bars, 5);

        // ── Disqualifiers ────────────────────────────────────────────────────

        $minPrice = (float) config('alpaca.screener_min_price', 5.0);
        if ($currentPrice < $minPrice) {
            return $this->save($symbol, $dateStr, 0, compact('currentPrice', 'sma5', 'sma20', 'atr', 'atrPct', 'upDays'), "Price below minimum (\${$minPrice}).");
        }

        $minAtrPct = (float) config('alpaca.screener_min_atr_pct', 1.0);
        $maxAtrPct = (float) config('alpaca.screener_max_atr_pct', 8.0);

        if ($atrPct !== null && $atrPct < $minAtrPct) {
            return $this->save($symbol, $dateStr, 0, compact('currentPrice', 'sma5', 'sma20', 'atr', 'atrPct', 'upDays'), "ATR% too low ({$atrPct}% < {$minAtrPct}%) — won't move enough.");
        }

        if ($atrPct !== null && $atrPct > $maxAtrPct) {
            return $this->save($symbol, $dateStr, 0, compact('currentPrice', 'sma5', 'sma20', 'atr', 'atrPct', 'upDays'), "ATR% too high ({$atrPct}% > {$maxAtrPct}%) — too volatile.");
        }

        if (EarningsEvent::isInBlackout($symbol)) {
            return $this->save($symbol, $dateStr, 0, compact('currentPrice', 'sma5', 'sma20', 'atr', 'atrPct', 'upDays'), 'Earnings blackout window active.');
        }

        // ── Scoring ──────────────────────────────────────────────────────────

        $score = $this->scoreMomentum($sma5, $sma20)
            + $this->scoreVolatility($atrPct)
            + $this->scoreTrend($upDays);

        return $this->save($symbol, $dateStr, $score, compact('currentPrice', 'sma5', 'sma20', 'atr', 'atrPct', 'upDays'));
    }

    /**
     * Momentum score (0–30 pts).
     * SMA5 above SMA20 = bullish. Wider separation = higher score. Capped at 30.
     */
    private function scoreMomentum(?float $sma5, ?float $sma20): float
    {
        if ($sma5 === null || $sma20 === null || $sma20 == 0) {
            return 0.0;
        }

        if ($sma5 <= $sma20) {
            return 0.0;
        }

        $separationPct = (($sma5 - $sma20) / $sma20) * 100;

        return (float) min(30, 15 + ($separationPct / 2) * 15);
    }

    /**
     * Volatility score (0–40 pts).
     * Sweet spot: 2–4% ATR. Linearly ramps in from 1% and out toward 8%.
     */
    private function scoreVolatility(?float $atrPct): float
    {
        if ($atrPct === null) {
            return 0.0;
        }

        if ($atrPct >= 2.0 && $atrPct <= 4.0) {
            return 40.0;
        }

        if ($atrPct >= 1.0 && $atrPct < 2.0) {
            return (float) (($atrPct - 1.0) / 1.0) * 40;
        }

        if ($atrPct > 4.0 && $atrPct <= 8.0) {
            return (float) ((8.0 - $atrPct) / 4.0) * 40;
        }

        return 0.0;
    }

    /**
     * Trend score (0–30 pts). 6 pts per up-day (close > open) in last 5 bars.
     */
    private function scoreTrend(?int $upDays): float
    {
        return (float) (($upDays ?? 0) * 6);
    }

    /**
     * Count how many of the last $n bars closed higher than they opened.
     *
     * @param  array<int, array<string, mixed>>  $bars
     */
    private function countUpDays(array $bars, int $n): int
    {
        $slice = array_slice($bars, -$n);

        return count(array_filter($slice, fn ($b) => (float) $b['c'] > (float) $b['o']));
    }

    /**
     * Persist a ScreenerCandidate row.
     *
     * @param  array<string, mixed>  $metrics
     */
    private function save(
        string $symbol,
        string $dateStr,
        float $score,
        array $metrics,
        ?string $disqualifiedReason = null,
    ): ScreenerCandidate {
        return ScreenerCandidate::create([
            'symbol' => $symbol,
            'screened_date' => $dateStr,
            'score' => round($score, 2),
            'price' => $metrics['currentPrice'] ?? null,
            'atr' => $metrics['atr'] ?? null,
            'atr_pct' => isset($metrics['atrPct']) ? round($metrics['atrPct'], 2) : null,
            'sma5' => $metrics['sma5'] ?? null,
            'sma20' => $metrics['sma20'] ?? null,
            'up_days' => $metrics['upDays'] ?? null,
            'disqualified' => $disqualifiedReason !== null,
            'disqualified_reason' => $disqualifiedReason,
        ]);
    }
}
