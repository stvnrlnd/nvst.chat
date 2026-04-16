<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Alpaca API Credentials
    |--------------------------------------------------------------------------
    |
    | Your Alpaca API key ID and secret key. Set ALPACA_PAPER=true to use
    | the paper trading environment (recommended while testing).
    |
    */

    'key' => env('ALPACA_KEY'),
    'secret' => env('ALPACA_SECRET'),
    'paper' => env('ALPACA_PAPER', true),

    /*
    |--------------------------------------------------------------------------
    | API Base URLs
    |--------------------------------------------------------------------------
    */

    'base_url' => env('ALPACA_PAPER', true)
        ? 'https://paper-api.alpaca.markets'
        : 'https://api.alpaca.markets',

    'data_url' => 'https://data.alpaca.markets',

    /*
    |--------------------------------------------------------------------------
    | Trading Settings
    |--------------------------------------------------------------------------
    |
    | Controls how aggressively the bot sizes positions and manages risk.
    |
    | position_size_pct   — % of portfolio to deploy per trade (e.g. 0.05 = 5%)
    | max_position_pct    — max % of portfolio in any single symbol (e.g. 0.20 = 20%)
    | trading_enabled     — master kill-switch; set false to generate signals only
    |
    */

    'position_size_pct' => env('ALPACA_POSITION_SIZE_PCT', 0.05),
    'max_position_pct' => env('ALPACA_MAX_POSITION_PCT', 0.20),
    'trading_enabled' => env('ALPACA_TRADING_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Signal Strategy
    |--------------------------------------------------------------------------
    |
    | strategy — 'sma_crossover' (default) or 'ai' (uses Claude API)
    | sma_short — short moving average window in trading days
    | sma_long  — long moving average window in trading days
    |
    */

    'strategy' => env('ALPACA_STRATEGY', 'sma_crossover'),
    'sma_short' => env('ALPACA_SMA_SHORT', 5),
    'sma_long' => env('ALPACA_SMA_LONG', 20),
];
