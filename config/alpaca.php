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

    /*
    |--------------------------------------------------------------------------
    | Screener Settings
    |--------------------------------------------------------------------------
    |
    | Used by the nightly screener to score and rank watchlist symbols.
    |
    | screener_min_price  — ignore symbols trading below this price (penny stocks)
    | screener_min_atr_pct — minimum ATR as % of price; below this = won't move enough
    | screener_max_atr_pct — maximum ATR as % of price; above this = too volatile
    | screener_top_n      — how many top candidates to keep per screener run
    |
    */

    'screener_min_price' => env('ALPACA_SCREENER_MIN_PRICE', 5.0),
    'screener_min_atr_pct' => env('ALPACA_SCREENER_MIN_ATR_PCT', 1.0),
    'screener_max_atr_pct' => env('ALPACA_SCREENER_MAX_ATR_PCT', 8.0),
    'screener_top_n' => env('ALPACA_SCREENER_TOP_N', 5),

    /*
    | Universe mode — when enabled, the screener fetches the top N most-active
    | symbols from Alpaca and merges them with the watchlist before scoring.
    | This removes the need to manually curate the watchlist for the screener.
    |
    | screener_use_universe  — set true to enable broad universe scanning
    | screener_universe_size — how many most-actives to pull from Alpaca
    */

    'screener_use_universe' => env('ALPACA_SCREENER_USE_UNIVERSE', false),
    'screener_universe_size' => env('ALPACA_SCREENER_UNIVERSE_SIZE', 50),

    /*
    |--------------------------------------------------------------------------
    | Automated Watchlist
    |--------------------------------------------------------------------------
    |
    | When enabled, SyncAutoWatchlistJob runs after market close each day,
    | pulling the most-active and top-gaining symbols from Alpaca and upserting
    | them into the stocks table with source='auto'. These symbols participate
    | in the nightly screener and ORB strategy alongside manually added symbols.
    |
    | auto_watchlist_enabled   — master toggle for the auto-discovery job
    | auto_watchlist_size      — how many symbols to pull from each Alpaca feed
    |                            (most-actives + top gainers, deduplicated)
    | auto_watchlist_stale_days — auto symbols not seen within this many days
    |                             are deactivated (manual symbols are never touched)
    |
    */

    'auto_watchlist_enabled' => env('ALPACA_AUTO_WATCHLIST_ENABLED', true),
    'auto_watchlist_size' => env('ALPACA_AUTO_WATCHLIST_SIZE', 50),
    'auto_watchlist_stale_days' => env('ALPACA_AUTO_WATCHLIST_STALE_DAYS', 7),
];
