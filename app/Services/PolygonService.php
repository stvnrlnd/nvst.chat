<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PolygonService
{
    private const BASE_URL = 'https://api.polygon.io';

    private function client(): PendingRequest
    {
        $key = config('services.polygon.key');

        if (! $key) {
            throw new RuntimeException('Polygon API key is not configured. Set POLYGON_API_KEY in .env.');
        }

        return Http::baseUrl(self::BASE_URL)
            ->withQueryParameters(['apiKey' => $key])
            ->acceptJson();
    }

    // -------------------------------------------------------------------------
    // News & Sentiment
    // -------------------------------------------------------------------------

    /**
     * Fetch recent news articles for a symbol, with per-ticker sentiment.
     *
     * Each article may include an `insights` array with sentiment per mentioned ticker:
     *   ['ticker' => 'AAPL', 'sentiment' => 'positive', 'sentiment_reasoning' => '...']
     *
     * Results are cached for 30 minutes to stay within the free-tier rate limit.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getNews(string $symbol, int $limit = 10): array
    {
        $cacheKey = "polygon.news.{$symbol}.{$limit}";

        return Cache::remember($cacheKey, now()->addMinutes(30), function () use ($symbol, $limit) {
            try {
                $response = $this->client()
                    ->get('/v2/reference/news', [
                        'ticker' => $symbol,
                        'limit' => $limit,
                        'order' => 'desc',
                        'sort' => 'published_utc',
                    ])
                    ->throw()
                    ->json();

                return $response['results'] ?? [];
            } catch (\Throwable $e) {
                Log::warning("Polygon: could not fetch news for {$symbol}: {$e->getMessage()}");

                return [];
            }
        });
    }

    /**
     * Compute an aggregate sentiment score for a symbol from recent news.
     *
     * Returns a float from -1.0 (all negative) to +1.0 (all positive),
     * or null if there are no scored articles.
     *
     * Scoring: positive = +1, neutral = 0, negative = -1, averaged across
     * articles that mention the symbol in their insights array.
     */
    public function getSentimentScore(string $symbol, int $articleLimit = 10): ?float
    {
        $articles = $this->getNews($symbol, $articleLimit);

        $scores = [];

        foreach ($articles as $article) {
            foreach ($article['insights'] ?? [] as $insight) {
                if (strtoupper($insight['ticker'] ?? '') !== strtoupper($symbol)) {
                    continue;
                }

                $scores[] = match ($insight['sentiment'] ?? '') {
                    'positive' => 1.0,
                    'negative' => -1.0,
                    default => 0.0,
                };
            }
        }

        if (empty($scores)) {
            return null;
        }

        return array_sum($scores) / count($scores);
    }

    // -------------------------------------------------------------------------
    // Earnings Calendar
    // -------------------------------------------------------------------------

    /**
     * Fetch upcoming and recent earnings dates for a symbol.
     *
     * Uses Polygon's ticker events endpoint. Results are cached for 6 hours
     * since earnings dates don't change frequently.
     *
     * Returns an array of dates (Y-m-d strings), sorted ascending.
     *
     * @return string[]
     */
    public function getEarningsDates(string $symbol): array
    {
        $cacheKey = "polygon.earnings.{$symbol}";

        return Cache::remember($cacheKey, now()->addHours(6), function () use ($symbol) {
            try {
                $response = $this->client()
                    ->get("/vX/reference/tickers/{$symbol}/events")
                    ->throw()
                    ->json();

                $dates = [];

                foreach ($response['results']['events'] ?? [] as $event) {
                    if (($event['type'] ?? '') !== 'earnings') {
                        continue;
                    }

                    $date = $event['date'] ?? null;

                    if ($date) {
                        $dates[] = $date;
                    }
                }

                sort($dates);

                return $dates;
            } catch (\Throwable $e) {
                Log::warning("Polygon: could not fetch earnings dates for {$symbol}: {$e->getMessage()}");

                return [];
            }
        });
    }

    /**
     * Check whether a symbol has an earnings report within the blackout window.
     *
     * @param  int  $daysBefore  Days before earnings to start blocking
     * @param  int  $daysAfter   Days after earnings to keep blocking
     */
    public function isInEarningsBlackout(string $symbol, int $daysBefore = 2, int $daysAfter = 1): bool
    {
        $dates = $this->getEarningsDates($symbol);

        $today = now()->startOfDay();

        foreach ($dates as $date) {
            $earningsDay = \Illuminate\Support\Carbon::parse($date)->startOfDay();
            $windowStart = $earningsDay->copy()->subDays($daysBefore);
            $windowEnd = $earningsDay->copy()->addDays($daysAfter);

            if ($today->between($windowStart, $windowEnd)) {
                return true;
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Macro / Market Data
    // -------------------------------------------------------------------------

    /**
     * Get the previous day's closing bar for a ticker (e.g. SPY for macro).
     *
     * Cached for 1 hour — previous close doesn't change during the day.
     *
     * @return array<string, mixed>|null
     */
    public function getPreviousClose(string $symbol): ?array
    {
        $cacheKey = "polygon.prev_close.{$symbol}";

        return Cache::remember($cacheKey, now()->addHour(), function () use ($symbol) {
            try {
                $response = $this->client()
                    ->get("/v2/aggs/ticker/{$symbol}/prev")
                    ->throw()
                    ->json();

                return $response['results'][0] ?? null;
            } catch (\Throwable $e) {
                Log::warning("Polygon: could not fetch previous close for {$symbol}: {$e->getMessage()}");

                return null;
            }
        });
    }
}
