<?php

namespace Database\Factories;

use App\Models\Position;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Position>
 */
class PositionFactory extends Factory
{
    public function definition(): array
    {
        $avgEntry = fake()->randomFloat(2, 10, 500);
        $qty = fake()->randomFloat(4, 0.1, 100);
        $currentPrice = $avgEntry * fake()->randomFloat(3, 0.85, 1.20);
        $marketValue = $currentPrice * $qty;
        $costBasis = $avgEntry * $qty;
        $unrealizedPl = $marketValue - $costBasis;
        $unrealizedPlpc = $costBasis > 0 ? $unrealizedPl / $costBasis : 0;

        return [
            'symbol' => strtoupper(fake()->lexify('???')),
            'qty' => $qty,
            'avg_entry_price' => $avgEntry,
            'current_price' => $currentPrice,
            'market_value' => $marketValue,
            'unrealized_pl' => $unrealizedPl,
            'unrealized_plpc' => $unrealizedPlpc,
            'synced_at' => now(),
        ];
    }
}
