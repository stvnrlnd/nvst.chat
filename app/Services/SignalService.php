<?php

namespace App\Services;

use App\Enums\SignalAction;
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

        return match ($strategy) {
            'ai' => $this->generateAiSignal($symbol),
            default => $this->generateSmaCrossoverSignal($symbol),
        };
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
