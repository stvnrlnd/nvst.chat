<?php

namespace Database\Factories;

use App\Enums\OrderType;
use App\Enums\TradeSide;
use App\Enums\TradeStatus;
use App\Models\Trade;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Trade>
 */
class TradeFactory extends Factory
{
    public function definition(): array
    {
        $qty = fake()->randomFloat(4, 0.1, 50);
        $price = fake()->randomFloat(2, 10, 500);

        return [
            'symbol' => strtoupper(fake()->lexify('???')),
            'side' => fake()->randomElement(TradeSide::cases()),
            'qty' => $qty,
            'order_type' => OrderType::Market,
            'status' => TradeStatus::Filled,
            'filled_avg_price' => $price,
            'filled_qty' => $qty,
            'alpaca_order_id' => fake()->uuid(),
            'submitted_at' => now()->subMinutes(fake()->numberBetween(1, 60)),
            'filled_at' => now()->subMinutes(fake()->numberBetween(0, 30)),
        ];
    }

    public function pending(): static
    {
        return $this->state([
            'status' => TradeStatus::Pending,
            'filled_avg_price' => null,
            'filled_qty' => null,
            'filled_at' => null,
        ]);
    }

    public function buy(): static
    {
        return $this->state(['side' => TradeSide::Buy]);
    }

    public function sell(): static
    {
        return $this->state(['side' => TradeSide::Sell]);
    }
}
