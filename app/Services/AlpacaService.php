<?php

namespace App\Services;

use App\DTOs\AccountStatus;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AlpacaService
{
    private string $baseUrl;

    private string $dataUrl;

    public function __construct()
    {
        $this->baseUrl = config('alpaca.paper')
            ? 'https://paper-api.alpaca.markets'
            : 'https://api.alpaca.markets';

        $this->dataUrl = 'https://data.alpaca.markets';
    }

    // -------------------------------------------------------------------------
    // Account
    // -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function getAccount(): array
    {
        return $this->trading()->get('/v2/account')->throw()->json();
    }

    public function getAccountStatus(): AccountStatus
    {
        return AccountStatus::fromAlpaca($this->getAccount());
    }

    // -------------------------------------------------------------------------
    // Positions
    // -------------------------------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    public function getPositions(): array
    {
        return $this->trading()->get('/v2/positions')->throw()->json();
    }

    /** @return array<string, mixed> */
    public function getPosition(string $symbol): array
    {
        return $this->trading()->get("/v2/positions/{$symbol}")->throw()->json();
    }

    // -------------------------------------------------------------------------
    // Orders
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function submitOrder(array $payload): array
    {
        return $this->trading()->post('/v2/orders', $payload)->throw()->json();
    }

    /** @return array<string, mixed> */
    public function getOrder(string $orderId): array
    {
        return $this->trading()->get("/v2/orders/{$orderId}")->throw()->json();
    }

    /** @return array<int, array<string, mixed>> */
    public function getOrders(string $status = 'all', int $limit = 50): array
    {
        return $this->trading()
            ->get('/v2/orders', ['status' => $status, 'limit' => $limit])
            ->throw()
            ->json();
    }

    // -------------------------------------------------------------------------
    // Market Data
    // -------------------------------------------------------------------------

    /**
     * Get the latest quote for a symbol.
     *
     * @return array<string, mixed>
     */
    public function getLatestQuote(string $symbol): array
    {
        $response = $this->data()
            ->get("/v2/stocks/{$symbol}/quotes/latest")
            ->throw()
            ->json();

        return $response['quote'] ?? [];
    }

    /**
     * Get daily OHLCV bars for a symbol.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getBars(string $symbol, int $limit = 30, string $timeframe = '1Day'): array
    {
        $response = $this->data()
            ->get("/v2/stocks/{$symbol}/bars", [
                'timeframe' => $timeframe,
                'limit' => $limit,
                'adjustment' => 'split',
            ])
            ->throw()
            ->json();

        return $response['bars'] ?? [];
    }

    /**
     * Get the latest trade price for a symbol.
     *
     * @return array<string, mixed>
     */
    public function getLatestTrade(string $symbol): array
    {
        $response = $this->data()
            ->get("/v2/stocks/{$symbol}/trades/latest")
            ->throw()
            ->json();

        return $response['trade'] ?? [];
    }

    // -------------------------------------------------------------------------
    // Screener
    // -------------------------------------------------------------------------

    /**
     * Top gainers and losers for the current trading day.
     *
     * Returns ['gainers' => [...], 'losers' => [...]]
     * Each entry: symbol, price, change, percent_change, volume, trade_count
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function getTopMovers(int $top = 20): array
    {
        return $this->data()
            ->get('/v1beta1/screener/stocks/movers', ['top' => $top])
            ->throw()
            ->json();
    }

    /**
     * Most actively traded stocks for the current trading day.
     *
     * Returns ['most_actives' => [...]]
     * Each entry: symbol, volume, trade_count, price, change, percent_change
     *
     * @return array<string, mixed>
     */
    public function getMostActives(int $top = 20): array
    {
        return $this->data()
            ->get('/v1beta1/screener/stocks/most-actives', ['top' => $top])
            ->throw()
            ->json();
    }

    // -------------------------------------------------------------------------
    // Market Clock
    // -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function getClock(): array
    {
        return $this->trading()->get('/v2/clock')->throw()->json();
    }

    public function isMarketOpen(): bool
    {
        return (bool) ($this->getClock()['is_open'] ?? false);
    }

    // -------------------------------------------------------------------------
    // HTTP Clients
    // -------------------------------------------------------------------------

    private function trading(): PendingRequest
    {
        $key = config('alpaca.key');
        $secret = config('alpaca.secret');

        if (! $key || ! $secret) {
            throw new RuntimeException('Alpaca API credentials are not configured.');
        }

        return Http::baseUrl($this->baseUrl)
            ->withHeaders([
                'APCA-API-KEY-ID' => $key,
                'APCA-API-SECRET-KEY' => $secret,
            ])
            ->acceptJson();
    }

    private function data(): PendingRequest
    {
        $key = config('alpaca.key');
        $secret = config('alpaca.secret');

        if (! $key || ! $secret) {
            throw new RuntimeException('Alpaca API credentials are not configured.');
        }

        return Http::baseUrl($this->dataUrl)
            ->withHeaders([
                'APCA-API-KEY-ID' => $key,
                'APCA-API-SECRET-KEY' => $secret,
            ])
            ->acceptJson();
    }
}
