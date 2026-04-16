<?php

namespace Database\Factories;

use App\Enums\SignalAction;
use App\Models\Signal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Signal>
 */
class SignalFactory extends Factory
{
    public function definition(): array
    {
        return [
            'symbol' => strtoupper(fake()->lexify('???')),
            'action' => fake()->randomElement(SignalAction::cases()),
            'price_at_signal' => fake()->randomFloat(2, 10, 500),
            'reason' => fake()->sentence(),
            'confidence' => fake()->randomFloat(2, 0.5, 0.95),
            'executed' => false,
        ];
    }

    public function buy(): static
    {
        return $this->state(['action' => SignalAction::Buy]);
    }

    public function sell(): static
    {
        return $this->state(['action' => SignalAction::Sell]);
    }

    public function hold(): static
    {
        return $this->state(['action' => SignalAction::Hold]);
    }

    public function executed(): static
    {
        return $this->state(['executed' => true]);
    }
}
