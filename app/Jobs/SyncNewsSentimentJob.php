<?php

namespace App\Jobs;

use App\Models\NewsArticle;
use App\Models\Stock;
use App\Services\PolygonService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncNewsSentimentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct()
    {
        $this->onQueue('sync');
    }

    /**
     * Fetch and persist recent news + sentiment for all active watchlist symbols.
     *
     * Scheduled every 30 minutes during market hours. Skips silently if
     * POLYGON_API_KEY is not configured.
     */
    public function handle(PolygonService $polygon): void
    {
        if (! config('services.polygon.key')) {
            Log::info('SyncNewsSentimentJob: POLYGON_API_KEY not set, skipping.');

            return;
        }

        $symbols = Stock::active()->pluck('symbol');

        foreach ($symbols as $symbol) {
            try {
                $articles = $polygon->getNews($symbol, 10);

                foreach ($articles as $article) {
                    $polygonId = $article['id'] ?? null;

                    if (! $polygonId) {
                        continue;
                    }

                    // Extract the per-ticker insight for this symbol
                    $insight = collect($article['insights'] ?? [])
                        ->first(fn ($i) => strtoupper($i['ticker'] ?? '') === strtoupper($symbol));

                    NewsArticle::updateOrCreate(
                        ['polygon_id' => $polygonId],
                        [
                            'symbol' => $symbol,
                            'title' => $article['title'] ?? '',
                            'url' => $article['article_url'] ?? '',
                            'published_at' => $article['published_utc'] ?? now(),
                            'sentiment' => $insight['sentiment'] ?? null,
                            'sentiment_reasoning' => $insight['sentiment_reasoning'] ?? null,
                            'author' => $article['author'] ?? null,
                        ],
                    );
                }

                Log::info("SyncNewsSentimentJob: synced {$symbol} — ".count($articles).' article(s).');
            } catch (\Throwable $e) {
                Log::warning("SyncNewsSentimentJob: failed for {$symbol}: {$e->getMessage()}");
            }
        }
    }
}
