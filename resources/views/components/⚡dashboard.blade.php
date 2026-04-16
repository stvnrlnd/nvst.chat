<?php

use App\Models\Position;
use App\Models\Signal;
use App\Models\Stock;
use App\Models\Trade;
use App\Services\AlpacaService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('Dashboard')] class extends Component
{
    public bool $marketOpen = false;

    public function mount(AlpacaService $alpaca): void
    {
        try {
            $this->marketOpen = (bool) ($alpaca->getClock()['is_open'] ?? false);
        } catch (\Throwable) {
            //
        }
    }

    public function with(AlpacaService $alpaca): array
    {
        $account = null;

        try {
            $account = $alpaca->getAccountStatus();
        } catch (\Throwable) {
            //
        }

        return [
            'portfolioValue' => $account?->portfolioValue,
            'buyingPower' => $account?->buyingPower,
            'daytradeCount' => $account?->daytradeCount ?? 0,
            'remainingDayTrades' => $account?->remainingDayTrades() ?? 3,
            'patternDayTrader' => $account?->patternDayTrader ?? false,
            'isPdtRestricted' => $account?->isPdtRestricted() ?? false,
            'isNearPdtLimit' => $account?->isNearPdtLimit() ?? false,
            'dayPl' => $account?->dayPl(),
            'positionCount' => Position::count(),
            'watchlistCount' => Stock::active()->count(),
            'recentSignals' => Signal::orderByDesc('created_at')->limit(5)->get(),
            'recentTrades' => Trade::orderByDesc('created_at')->limit(5)->get(),
            'tradingEnabled' => config('alpaca.trading_enabled'),
            'isPaper' => config('alpaca.paper'),
        ];
    }
};
?>

<div class="flex flex-col gap-6 p-4">

    {{-- Page heading --}}
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Dashboard</flux:heading>
        <flux:button size="xs" variant="ghost" icon="question-mark-circle" x-on:click="window.startTour('dashboard')">Take Tour</flux:button>
    </div>

    {{-- Status Bar --}}
    <div id="tour-status-bar" class="flex items-center gap-3 text-sm">
        <flux:tooltip position="bottom" content="No real money involved — Alpaca simulates orders in a paper portfolio so you can test safely.">
            @if($isPaper)
                <flux:badge color="yellow" size="sm">Paper Trading</flux:badge>
            @else
                <flux:badge color="blue" size="sm">Live Trading</flux:badge>
            @endif
        </flux:tooltip>

        <flux:tooltip position="bottom" content="The NYSE and NASDAQ are open Mon–Fri, 9:30am–4:00pm ET. The bot only runs during these hours.">
            @if($marketOpen)
                <flux:badge color="green" size="sm" icon="signal">Market Open</flux:badge>
            @else
                <flux:badge color="zinc" size="sm">Market Closed</flux:badge>
            @endif
        </flux:tooltip>

        <flux:tooltip position="bottom" content="When Off, signals are generated and logged but no orders are ever submitted. Safe to leave Off while you're learning.">
            @if($tradingEnabled)
                <flux:badge color="green" size="sm" icon="bolt">Auto-Trading On</flux:badge>
            @else
                <flux:badge color="zinc" size="sm" icon="pause">Signals Only</flux:badge>
            @endif
        </flux:tooltip>

        <flux:tooltip position="bottom" :content="'Day trades used in the last 5 business days. The PDT rule flags your account if you exceed 3 day trades (buy + same-day sell) in a rolling 5-day window. Flagged accounts under $25k equity are restricted to close-only orders.'">
            @if($isPdtRestricted)
                <flux:badge color="red" size="sm" icon="exclamation-triangle">PDT Restricted</flux:badge>
            @elseif($daytradeCount >= 3)
                <flux:badge color="red" size="sm">{{ $daytradeCount }}/3 Day Trades</flux:badge>
            @elseif($isNearPdtLimit)
                <flux:badge color="yellow" size="sm">{{ $daytradeCount }}/3 Day Trades</flux:badge>
            @else
                <flux:badge color="zinc" size="sm">{{ $daytradeCount }}/3 Day Trades</flux:badge>
            @endif
        </flux:tooltip>
    </div>

    {{-- PDT Warning Banner --}}
    @if($isPdtRestricted)
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-300">
            <strong>PDT Restriction Active.</strong> This account is flagged as a pattern day trader with equity below $25,000. All new orders are blocked until equity is restored above the threshold or the PDT flag is resolved with Alpaca.
        </div>
    @elseif($daytradeCount >= 3)
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-300">
            <strong>Day trade limit reached ({{ $daytradeCount }}/3).</strong> Same-day sells are blocked for the rest of this 5-business-day window to prevent PDT flagging. Positions can still be held and sold on a future day.
        </div>
    @elseif($isNearPdtLimit)
        <div class="rounded-lg border border-yellow-200 bg-yellow-50 px-4 py-3 text-sm text-yellow-800 dark:border-yellow-800 dark:bg-yellow-950 dark:text-yellow-300">
            <strong>PDT warning: {{ $remainingDayTrades }} day trade{{ $remainingDayTrades === 1 ? '' : 's' }} remaining</strong> in the current 5-business-day window. The bot will block same-day sells once the limit is hit.
        </div>
    @endif

    {{-- Portfolio Stats --}}
    <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
        <div id="tour-portfolio-value" class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <div class="flex items-center gap-1">
                <flux:text size="xs" class="uppercase tracking-wide text-neutral-500">Portfolio Value</flux:text>
                <flux:tooltip content="The total worth of your account: all open positions at current prices, plus uninvested cash.">
                    <flux:icon.question-mark-circle class="size-3 cursor-help text-neutral-400" />
                </flux:tooltip>
            </div>
            <p class="mt-1 text-2xl font-semibold">
                {{ $portfolioValue ? '$'.number_format($portfolioValue, 2) : '—' }}
            </p>
        </div>

        <div id="tour-buying-power" class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <div class="flex items-center gap-1">
                <flux:text size="xs" class="uppercase tracking-wide text-neutral-500">Buying Power</flux:text>
                <flux:tooltip content="Cash available for new orders right now. May include margin (borrowed funds) from Alpaca.">
                    <flux:icon.question-mark-circle class="size-3 cursor-help text-neutral-400" />
                </flux:tooltip>
            </div>
            <p class="mt-1 text-2xl font-semibold">
                {{ $buyingPower ? '$'.number_format($buyingPower, 2) : '—' }}
            </p>
        </div>

        <div id="tour-day-pl" class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <div class="flex items-center gap-1">
                <flux:text size="xs" class="uppercase tracking-wide text-neutral-500">Today's P&L</flux:text>
                <flux:tooltip content="Profit and loss since yesterday's market close. Resets every morning. P&L = Profit &amp; Loss.">
                    <flux:icon.question-mark-circle class="size-3 cursor-help text-neutral-400" />
                </flux:tooltip>
            </div>
            <p class="mt-1 text-2xl font-semibold {{ $dayPl !== null && $dayPl >= 0 ? 'text-green-600' : 'text-red-600' }}">
                @if($dayPl !== null)
                    {{ $dayPl >= 0 ? '+' : '' }}${{ number_format($dayPl, 2) }}
                @else
                    —
                @endif
            </p>
        </div>

        <div id="tour-counts" class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <div class="flex items-center gap-1">
                <flux:text size="xs" class="uppercase tracking-wide text-neutral-500">Positions / Watching</flux:text>
                <flux:tooltip content="Positions = stocks you currently own shares in. Watching = active symbols the bot monitors for signals.">
                    <flux:icon.question-mark-circle class="size-3 cursor-help text-neutral-400" />
                </flux:tooltip>
            </div>
            <p class="mt-1 text-2xl font-semibold">{{ $positionCount }} / {{ $watchlistCount }}</p>
        </div>
    </div>

    <div class="grid gap-6 md:grid-cols-2">
        {{-- Recent Signals --}}
        <div id="tour-recent-signals" class="rounded-xl border border-neutral-200 dark:border-neutral-700">
            <div class="flex items-center justify-between border-b border-neutral-200 px-4 py-3 dark:border-neutral-700">
                <div class="flex items-center gap-1">
                    <flux:heading size="sm">Recent Signals</flux:heading>
                    <flux:tooltip content="A signal is the bot's recommendation: Buy, Sell, or Hold. Generated every 5 minutes per symbol during market hours.">
                        <flux:icon.question-mark-circle class="size-3 cursor-help text-neutral-400" />
                    </flux:tooltip>
                </div>
                <flux:link href="{{ route('signals') }}" size="sm">View all</flux:link>
            </div>

            @if($recentSignals->isEmpty())
                <p class="px-4 py-6 text-sm text-neutral-500">No signals yet. Add stocks to your watchlist.</p>
            @else
                <div class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @foreach($recentSignals as $signal)
                        <div class="flex items-center justify-between px-4 py-3">
                            <div class="flex items-center gap-3">
                                <flux:badge :color="$signal->action->color()" size="sm">{{ $signal->action->label() }}</flux:badge>
                                <span class="font-medium">{{ $signal->symbol }}</span>
                            </div>
                            <flux:text size="sm" class="text-neutral-500">{{ $signal->created_at->diffForHumans() }}</flux:text>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Recent Trades --}}
        <div id="tour-recent-trades" class="rounded-xl border border-neutral-200 dark:border-neutral-700">
            <div class="flex items-center justify-between border-b border-neutral-200 px-4 py-3 dark:border-neutral-700">
                <div class="flex items-center gap-1">
                    <flux:heading size="sm">Recent Trades</flux:heading>
                    <flux:tooltip content="Actual orders submitted to Alpaca. A signal only becomes a trade when Auto-Trading is On.">
                        <flux:icon.question-mark-circle class="size-3 cursor-help text-neutral-400" />
                    </flux:tooltip>
                </div>
                <flux:link href="{{ route('trades') }}" size="sm">View all</flux:link>
            </div>

            @if($recentTrades->isEmpty())
                <p class="px-4 py-6 text-sm text-neutral-500">No trades yet.</p>
            @else
                <div class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @foreach($recentTrades as $trade)
                        <div class="flex items-center justify-between px-4 py-3">
                            <div class="flex items-center gap-3">
                                <flux:badge :color="$trade->side === \App\Enums\TradeSide::Buy ? 'green' : 'red'" size="sm">
                                    {{ strtoupper($trade->side->value) }}
                                </flux:badge>
                                <span class="font-medium">{{ $trade->symbol }}</span>
                                <flux:text size="sm" class="text-neutral-500">{{ number_format($trade->qty, 4) }} shares</flux:text>
                            </div>
                            <flux:badge :color="$trade->status->color()" size="sm">{{ $trade->status->value }}</flux:badge>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
