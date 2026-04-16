<?php

namespace App\Services;

use App\Models\Position;
use Illuminate\Support\Facades\Log;

class MarketDataService
{
    public function __construct(private readonly AlpacaService $alpaca) {}

    /**
     * Get the current price for a symbol.
     */
    public function getCurrentPrice(string $symbol): ?float
    {
        try {
            $trade = $this->alpaca->getLatestTrade($symbol);

            return isset($trade['p']) ? (float) $trade['p'] : null;
        } catch (\Throwable $e) {
            Log::warning("Could not fetch price for {$symbol}: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * Get closing prices from daily bars (most recent last).
     *
     * @return float[]
     */
    public function getClosingPrices(string $symbol, int $limit = 30): array
    {
        try {
            $bars = $this->alpaca->getBars($symbol, $limit);

            return array_map(fn ($bar) => (float) $bar['c'], $bars);
        } catch (\Throwable $e) {
            Log::warning("Could not fetch bars for {$symbol}: {$e->getMessage()}");

            return [];
        }
    }

    /**
     * Refresh current_price and market_value on all open positions.
     */
    public function refreshPositionPrices(): void
    {
        $positions = Position::all();

        foreach ($positions as $position) {
            $price = $this->getCurrentPrice($position->symbol);

            if ($price !== null) {
                $marketValue = $price * (float) $position->qty;
                $unrealizedPl = $marketValue - ((float) $position->avg_entry_price * (float) $position->qty);
                $costBasis = (float) $position->avg_entry_price * (float) $position->qty;
                $unrealizedPlpc = $costBasis > 0 ? $unrealizedPl / $costBasis : 0;

                $position->update([
                    'current_price' => $price,
                    'market_value' => $marketValue,
                    'unrealized_pl' => $unrealizedPl,
                    'unrealized_plpc' => $unrealizedPlpc,
                    'synced_at' => now(),
                ]);
            }
        }
    }

    /**
     * Calculate a simple moving average from a price array.
     *
     * @param  float[]  $prices
     */
    public function sma(array $prices, int $period): ?float
    {
        if (count($prices) < $period) {
            return null;
        }

        $slice = array_slice($prices, -$period);

        return array_sum($slice) / $period;
    }
}
