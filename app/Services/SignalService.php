<?php

namespace App\Services;

use App\Enums\SignalAction;
use App\Models\NewsArticle;
use App\Models\Signal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SignalService
{
    public function __construct(private readonly MarketDataService $marketData) {}

    /**
     * Generate a signal for the given symbol using the configured strategy.
     */
    public function generateSignal(string $symbol): Signal
    {
        $strategy = config('alpaca.strategy', 'sma_crossover');

        $signal = match ($strategy) {
            'ai' => $this->generateAiSignal($symbol),
            default => $this->generateSmaCrossoverSignal($symbol),
        };

        // When not using AI, apply the rule-based sentiment filter to BUY signals.
        // The AI strategy already receives sentiment as context in its prompt.
        if ($strategy !== 'ai' && $signal->action === SignalAction::Buy) {
            $signal = $this->applySentimentFilter($signal);
        }

        return $signal;
    }

    /**
     * Downgrade a BUY signal to HOLD if recent news sentiment is strongly negative.
     *
     * When AI is active, sentiment is passed as context to the prompt instead so
     * the model can weigh it alongside other factors rather than a binary rule.
     */
    private function applySentimentFilter(Signal $signal): Signal
    {
        $threshold = config('alpaca.sentiment_threshold');

        if ($threshold === null) {
            return $signal;
        }

        $score = NewsArticle::aggregateSentiment($signal->symbol);

        // No articles means no sentiment data — don't suppress
        if ($score === null) {
            return $signal;
        }

        if ($score <= (float) $threshold) {
            $label = NewsArticle::sentimentLabel($score);
            $reason = "Sentiment filter: BUY suppressed due to {$label} news sentiment (score: ".number_format($score, 2)."). Original signal: {$signal->reason}";

            Log::info("Sentiment filter suppressed BUY for {$signal->symbol} (score: {$score}).");

            // Mark the original signal executed and return a new HOLD
            $signal->update(['executed' => true]);

            return $this->saveSignal($signal->symbol, SignalAction::Hold, $signal->price_at_signal, $reason);
        }

        return $signal;
    }

    /**
     * SMA crossover strategy:
     *   - BUY  when short SMA crosses above long SMA
     *   - SELL when short SMA crosses below long SMA
     *   - HOLD otherwise
     */
    private function generateSmaCrossoverSignal(string $symbol): Signal
    {
        $shortPeriod = (int) config('alpaca.sma_short', 5);
        $longPeriod = (int) config('alpaca.sma_long', 20);
        $barsNeeded = $longPeriod + 2;

        $prices = $this->marketData->getClosingPrices($symbol, $barsNeeded);

        if (count($prices) < $longPeriod + 1) {
            return $this->saveSignal($symbol, SignalAction::Hold, null, 'Insufficient price history for SMA calculation.');
        }

        $currentPrice = end($prices);

        // Current SMA values (using all prices up to today)
        $shortSmaNow = $this->marketData->sma($prices, $shortPeriod);
        $longSmaNow = $this->marketData->sma($prices, $longPeriod);

        // Previous bar's SMA values (prices excluding today)
        $pricesPrev = array_slice($prices, 0, -1);
        $shortSmaPrev = $this->marketData->sma($pricesPrev, $shortPeriod);
        $longSmaPrev = $this->marketData->sma($pricesPrev, $longPeriod);

        if ($shortSmaNow === null || $longSmaNow === null || $shortSmaPrev === null || $longSmaPrev === null) {
            return $this->saveSignal($symbol, SignalAction::Hold, $currentPrice, 'Could not calculate SMA values.');
        }

        $crossedAbove = $shortSmaPrev <= $longSmaPrev && $shortSmaNow > $longSmaNow;
        $crossedBelow = $shortSmaPrev >= $longSmaPrev && $shortSmaNow < $longSmaNow;

        if ($crossedAbove) {
            $reason = "SMA{$shortPeriod} ({$shortSmaNow}) crossed above SMA{$longPeriod} ({$longSmaNow})";

            return $this->saveSignal($symbol, SignalAction::Buy, $currentPrice, $reason, 0.70);
        }

        if ($crossedBelow) {
            $reason = "SMA{$shortPeriod} ({$shortSmaNow}) crossed below SMA{$longPeriod} ({$longSmaNow})";

            return $this->saveSignal($symbol, SignalAction::Sell, $currentPrice, $reason, 0.70);
        }

        $trend = $shortSmaNow > $longSmaNow ? 'bullish' : 'bearish';
        $reason = "No crossover — SMA{$shortPeriod} ({$shortSmaNow}) vs SMA{$longPeriod} ({$longSmaNow}), trend: {$trend}";

        return $this->saveSignal($symbol, SignalAction::Hold, $currentPrice, $reason);
    }

    /**
     * AI-powered strategy using Claude to analyze price action and generate a signal.
     *
     * Requires ANTHROPIC_API_KEY in .env.
     */
    private function generateAiSignal(string $symbol): Signal
    {
        $prices = $this->marketData->getClosingPrices($symbol, 30);

        if (count($prices) < 5) {
            return $this->saveSignal($symbol, SignalAction::Hold, null, 'Insufficient data for AI analysis.');
        }

        $currentPrice = end($prices);
        $priceHistory = implode(', ', array_map(fn ($p) => number_format($p, 2), array_slice($prices, -10)));

        // Include sentiment context for the AI — gives it a richer signal than price alone
        $sentimentScore = NewsArticle::aggregateSentiment($symbol);
        $sentimentLabel = NewsArticle::sentimentLabel($sentimentScore);
        $sentimentContext = $sentimentScore !== null
            ? "Recent news sentiment: {$sentimentLabel} (score: ".number_format($sentimentScore, 2).", range −1.0 to +1.0)."
            : 'Recent news sentiment: unavailable.';

        try {
            $response = Http::withHeaders([
                'x-api-key' => config('services.anthropic.key'),
                'anthropic-version' => '2023-06-01',
            ])->post('https://api.anthropic.com/v1/messages', [
                'model' => 'claude-haiku-4-5-20251001',
                'max_tokens' => 256,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => <<<PROMPT
                        You are a trading signal engine. Analyze the recent closing prices for {$symbol} and respond with ONLY valid JSON.

                        Recent closing prices (oldest to newest): {$priceHistory}
                        Current price: {$currentPrice}
                        {$sentimentContext}

                        Respond with exactly this JSON structure:
                        {"action": "buy|sell|hold", "confidence": 0.0-1.0, "reason": "brief one-sentence explanation"}
                        PROMPT,
                    ],
                ],
            ]);

            $content = $response->throw()->json('content.0.text');
            $data = json_decode($content, true);

            if (! $data || ! isset($data['action'])) {
                throw new \RuntimeException('Invalid JSON from Claude');
            }

            $action = SignalAction::from($data['action']);
            $confidence = (float) ($data['confidence'] ?? 0.5);
            $reason = $data['reason'] ?? 'AI analysis';

            return $this->saveSignal($symbol, $action, $currentPrice, "[AI] {$reason}", $confidence);
        } catch (\Throwable $e) {
            Log::error("AI signal generation failed for {$symbol}: {$e->getMessage()}");

            return $this->saveSignal($symbol, SignalAction::Hold, $currentPrice, 'AI analysis failed — holding.');
        }
    }

    private function saveSignal(
        string $symbol,
        SignalAction $action,
        ?float $price,
        ?string $reason = null,
        ?float $confidence = null,
    ): Signal {
        return Signal::create([
            'symbol' => $symbol,
            'action' => $action,
            'price_at_signal' => $price,
            'reason' => $reason,
            'confidence' => $confidence,
            'executed' => false,
        ]);
    }
}
