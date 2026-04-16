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

    /*
    |--------------------------------------------------------------------------
    | Sentiment Filter
    |--------------------------------------------------------------------------
    |
    | sentiment_threshold — aggregate sentiment score (−1.0 to +1.0) below
    | which a BUY signal is downgraded to HOLD. Set to null to disable.
    | When the AI strategy is active it receives sentiment as context instead.
    |
    */

    'sentiment_threshold' => env('ALPACA_SENTIMENT_THRESHOLD', -0.3),

    /*
    |--------------------------------------------------------------------------
    | Signal Cooldown
    |--------------------------------------------------------------------------
    |
    | Minimum minutes between non-Hold signals for the same symbol. Prevents
    | the same Buy or Sell from firing on every 5-minute cycle during a
    | sustained crossover. Set to 0 to disable.
    |
    */

    'signal_cooldown_minutes' => env('ALPACA_SIGNAL_COOLDOWN_MINUTES', 30),

    /*
    |--------------------------------------------------------------------------
    | Stop-Loss
    |--------------------------------------------------------------------------
    |
    | Maximum unrealized loss (as a decimal fraction) before a position is
    | force-sold regardless of the current signal. E.g. -0.05 = exit at -5%.
    | Set to null to disable stop-loss entirely.
    |
    */

    'stop_loss_pct' => env('ALPACA_STOP_LOSS_PCT', -0.05),

    /*
    |--------------------------------------------------------------------------
    | Macro Filter
    |--------------------------------------------------------------------------
    |
    | macro_symbol        — index/ETF to use as a market health proxy (default: SPY)
    | macro_bear_threshold — % intraday drop that marks the session as bearish;
    |                        BUY signals are suppressed when breached (e.g. -1.5 = down 1.5%)
    |
    */

    'macro_symbol' => env('ALPACA_MACRO_SYMBOL', 'SPY'),
    'macro_bear_threshold' => env('ALPACA_MACRO_BEAR_THRESHOLD', -1.5),
];
