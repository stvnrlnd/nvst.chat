<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'polygon_id',
    'symbol',
    'title',
    'url',
    'published_at',
    'sentiment',
    'sentiment_reasoning',
    'author',
])]
class NewsArticle extends Model
{
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    /**
     * Compute an aggregate sentiment score for a symbol from recent stored articles.
     *
     * Returns a float from -1.0 (all negative) to +1.0 (all positive), or null if no scored articles.
     */
    public static function aggregateSentiment(string $symbol, int $limit = 10): ?float
    {
        $scores = static::query()
            ->where('symbol', $symbol)
            ->whereNotNull('sentiment')
            ->orderByDesc('published_at')
            ->limit($limit)
            ->pluck('sentiment')
            ->map(fn ($s) => match ($s) {
                'positive' => 1.0,
                'negative' => -1.0,
                default => 0.0,
            });

        if ($scores->isEmpty()) {
            return null;
        }

        return $scores->sum() / $scores->count();
    }

    /**
     * Human-readable sentiment label from a numeric score.
     */
    public static function sentimentLabel(?float $score): string
    {
        if ($score === null) {
            return 'unknown';
        }

        if ($score >= 0.3) {
            return 'positive';
        }

        if ($score <= -0.3) {
            return 'negative';
        }

        return 'neutral';
    }
}
