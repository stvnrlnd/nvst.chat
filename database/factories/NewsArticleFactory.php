<?php

namespace Database\Factories;

use App\Models\NewsArticle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NewsArticle>
 */
class NewsArticleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'polygon_id' => fake()->uuid(),
            'symbol' => strtoupper(fake()->lexify('???')),
            'title' => fake()->sentence(),
            'url' => fake()->url(),
            'published_at' => fake()->dateTimeThisMonth(),
            'sentiment' => fake()->randomElement(['positive', 'neutral', 'negative']),
            'sentiment_reasoning' => fake()->sentence(),
            'author' => fake()->name(),
        ];
    }

    public function positive(): static
    {
        return $this->state(['sentiment' => 'positive']);
    }

    public function neutral(): static
    {
        return $this->state(['sentiment' => 'neutral']);
    }

    public function negative(): static
    {
        return $this->state(['sentiment' => 'negative']);
    }
}
