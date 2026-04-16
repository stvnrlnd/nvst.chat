import { driver } from 'driver.js';
import 'driver.js/dist/driver.css';

const tourConfig = {
    showProgress: true,
    animate: true,
    overlayOpacity: 0.4,
    smoothScroll: true,
    popoverClass: 'swamp-tour-popover',
};

const tours = {
    dashboard: () => driver({
        ...tourConfig,
        steps: [
            {
                element: '#tour-status-bar',
                popover: {
                    title: 'System Status',
                    description: 'These badges tell you the current operating mode at a glance. <strong>Paper Trading</strong> means no real money is at risk — Alpaca simulates everything. <strong>Auto-Trading Off</strong> means signals are generated and logged but no orders are ever submitted.',
                    side: 'bottom',
                },
            },
            {
                element: '#tour-portfolio-value',
                popover: {
                    title: 'Portfolio Value',
                    description: 'The total worth of your account: all open positions at their current market prices, plus uninvested cash. This is the big number — the one that matters most.',
                    side: 'bottom',
                },
            },
            {
                element: '#tour-buying-power',
                popover: {
                    title: 'Buying Power',
                    description: 'Cash available to place new orders right now. Alpaca may include margin (borrowed money) here, so it can be higher than your actual cash balance. This bot only uses what you actually have.',
                    side: 'bottom',
                },
            },
            {
                element: '#tour-day-pl',
                popover: {
                    title: "Today's P&L",
                    description: 'Profit and loss since yesterday\'s market close. Green = you\'re ahead of where you started today. Red = you\'re behind. This resets every morning.',
                    side: 'bottom',
                },
            },
            {
                element: '#tour-counts',
                popover: {
                    title: 'Positions / Watching',
                    description: '<strong>Positions</strong> are stocks you currently own shares in. <strong>Watching</strong> is the number of active ticker symbols the bot is generating signals for.',
                    side: 'bottom',
                },
            },
            {
                element: '#tour-recent-signals',
                popover: {
                    title: 'Recent Signals',
                    description: 'Signals are the bot\'s trading recommendations: Buy, Sell, or Hold. They\'re generated every 5 minutes during market hours for each stock on your watchlist. Visit the Signals page to see them all and trigger them manually.',
                    side: 'top',
                },
            },
            {
                element: '#tour-recent-trades',
                popover: {
                    title: 'Recent Trades',
                    description: 'Actual orders that were submitted to Alpaca. A signal becomes a trade only when Auto-Trading is On. Visit the Trade History page to see full details on every order.',
                    side: 'top',
                },
            },
        ],
    }).drive(),

    watchlist: () => driver({
        ...tourConfig,
        steps: [
            {
                element: '#tour-watchlist-heading',
                popover: {
                    title: 'Your Watchlist',
                    description: 'The watchlist controls which stocks the bot monitors. Every active symbol here gets a Buy/Sell/Hold signal generated every 5 minutes during market hours.',
                    side: 'bottom',
                },
            },
            {
                element: '#tour-add-symbol',
                popover: {
                    title: 'Adding a Stock',
                    description: 'Enter a ticker symbol — the short code that identifies a stock on an exchange. AAPL = Apple, MSFT = Microsoft, TSLA = Tesla, SPY = S&P 500 ETF, etc. Start with 3–5 well-known symbols while you learn how the bot behaves.',
                    side: 'bottom',
                },
            },
            {
                element: '#tour-watchlist-table',
                popover: {
                    title: 'Active vs Paused',
                    description: '<strong>Active</strong> symbols get signals every 5 minutes. <strong>Paused</strong> symbols are ignored — useful if you want to stop the bot from touching a stock temporarily without removing it entirely.',
                    side: 'top',
                },
            },
        ],
    }).drive(),

    positions: () => driver({
        ...tourConfig,
        steps: [
            {
                element: '#tour-positions-heading',
                popover: {
                    title: 'Open Positions',
                    description: 'A position is shares of a stock you currently own. When the bot buys a stock, a position opens. When it sells all shares, the position closes. This page syncs live from Alpaca.',
                    side: 'bottom',
                },
            },
            {
                element: '#tour-sync-btn',
                popover: {
                    title: 'Sync Now',
                    description: 'Forces an immediate pull of your current positions from Alpaca. Normally this happens automatically every minute during market hours.',
                    side: 'bottom',
                },
            },
            {
                element: '#tour-positions-table',
                popover: {
                    title: 'Position Details',
                    description: '<strong>Avg Entry Price</strong> — the average price you paid per share. If you bought 1 share at $100 and another at $120, your avg entry is $110.\n\n<strong>Unrealized P&L</strong> — your profit or loss if you sold right now. "Unrealized" means it\'s still on paper — it can still go up or down.\n\n<strong>Market Value</strong> — shares × current price.',
                    side: 'top',
                },
            },
        ],
    }).drive(),

    signals: () => driver({
        ...tourConfig,
        steps: [
            {
                element: '#tour-signals-heading',
                popover: {
                    title: 'What Are Signals?',
                    description: 'A signal is the bot\'s recommendation for a stock at a given moment: <strong>Buy</strong> (open or add to a position), <strong>Sell</strong> (close the position), or <strong>Hold</strong> (do nothing). Signals are generated every 5 minutes during market hours.',
                    side: 'bottom',
                },
            },
            {
                element: '#tour-manual-triggers',
                popover: {
                    title: 'Manual Signal Trigger',
                    description: 'Run the signal logic for a specific stock right now, outside the normal 5-minute schedule. Useful for testing or when you want a fresh read on a stock.',
                    side: 'bottom',
                },
            },
            {
                element: '#tour-signals-table',
                popover: {
                    title: 'Reading the Signal Log',
                    description: '<strong>Action</strong> — the recommendation.\n\n<strong>Confidence</strong> — only the AI strategy fills this in. It\'s the model\'s self-reported certainty from 0–100%. The SMA crossover strategy is purely rule-based, so confidence is always shown as N/A.\n\n<strong>Executed</strong> — whether this signal triggered an actual order. If Auto-Trading is Off, all signals will show Pending.',
                    side: 'top',
                },
            },
            {
                element: '#tour-strategy-note',
                popover: {
                    title: 'SMA Crossover Strategy',
                    description: 'By default the bot uses a <strong>Simple Moving Average (SMA) crossover</strong>. It watches two averages: a fast one (5-day) and a slow one (20-day). When the fast average crosses <em>above</em> the slow one, it\'s a Buy signal. When it crosses <em>below</em>, it\'s Sell. This is one of the oldest and most basic technical trading strategies.',
                    side: 'top',
                },
            },
        ],
    }).drive(),

    discover: () => driver({
        ...tourConfig,
        steps: [
            {
                element: '#tour-discover-context',
                popover: {
                    title: 'Finding Stocks to Trade',
                    description: 'This page pulls live data from Alpaca\'s market screener. It shows you which stocks are moving the most today — a practical way to find candidates worth watching.',
                    side: 'bottom',
                },
            },
            {
                element: '[data-tab="gainers"]',
                popover: {
                    title: 'Top Gainers',
                    description: 'Stocks with the biggest <em>upward</em> % move from yesterday\'s close. High momentum can mean a trend is forming — but it can also mean the move is almost over. The SMA strategy will help filter out noise once you\'re tracking it.',
                    side: 'bottom',
                },
            },
            {
                element: '[data-tab="losers"]',
                popover: {
                    title: 'Top Losers',
                    description: 'Stocks with the biggest <em>downward</em> move. Useful if you want to watch for a potential recovery, or to avoid stocks in a sharp decline.',
                    side: 'bottom',
                },
            },
            {
                element: '[data-tab="actives"]',
                popover: {
                    title: 'Most Active',
                    description: 'Stocks with the most shares traded today. High volume often signals news, earnings, or institutional buying/selling. Volume + momentum together is a strong combination.',
                    side: 'bottom',
                },
            },
        ],
    }).drive(),

    trades: () => driver({
        ...tourConfig,
        steps: [
            {
                element: '#tour-trades-heading',
                popover: {
                    title: 'Trade History',
                    description: 'Every order the bot has submitted to Alpaca, past and present. A trade is created when a Buy or Sell signal fires and Auto-Trading is enabled.',
                    side: 'bottom',
                },
            },
            {
                element: '#tour-trades-table',
                popover: {
                    title: 'Understanding a Trade',
                    description: '<strong>Side</strong> — Buy means shares were purchased; Sell means they were sold.\n\n<strong>Fill Price</strong> — the actual price the order executed at. The bot submits market orders, which execute at the best available price immediately.\n\n<strong>Status</strong> — <em>Pending</em> = submitted but not matched yet. <em>Filled</em> = completed. <em>Canceled/Rejected</em> = didn\'t go through.',
                    side: 'top',
                },
            },
        ],
    }).drive(),
};

window.startTour = (page) => {
    if (tours[page]) {
        tours[page]();
    }
};
