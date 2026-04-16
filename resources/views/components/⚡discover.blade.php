<?php

use App\Models\EarningsEvent;
use App\Models\Stock;
use App\Services\AlpacaService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('Discover')] class extends Component
{
    public string $tab = 'gainers';

    public bool $dataLoading = true;

    /** @var array<int, array<string, mixed>> */
    public array $gainers = [];

    /** @var array<int, array<string, mixed>> */
    public array $losers = [];

    /** @var array<int, array<string, mixed>> */
    public array $actives = [];

    public ?string $error = null;

    /** Called via wire:init — deferred so the page renders without waiting on Alpaca. */
    public function loadMarketData(AlpacaService $alpaca): void
    {
        $this->loadData($alpaca);
    }

    public function refresh(AlpacaService $alpaca): void
    {
        $this->dataLoading = true;
        $this->error = null;
        $this->loadData($alpaca);
    }

    private function loadData(AlpacaService $alpaca): void
    {
        try {
            $movers = $alpaca->getTopMovers(25);
            $this->gainers = $movers['gainers'] ?? [];
            $this->losers = $movers['losers'] ?? [];

            $actives = $alpaca->getMostActives(25);
            $this->actives = $actives['most_actives'] ?? [];
        } catch (\Throwable $e) {
            $this->error = 'Could not load market data: '.$e->getMessage();
        } finally {
            $this->dataLoading = false;
        }
    }

    public function addToWatchlist(string $symbol): void
    {
        Stock::firstOrCreate(
            ['symbol' => strtoupper($symbol)],
            ['is_active' => true],
        );

        session()->flash('added', $symbol);
    }

    public function with(): array
    {
        $watchlistSymbols = Stock::pluck('symbol')->map(fn ($s) => strtoupper($s))->all();

        // Only show blackout status for symbols we have earnings data for
        $blackoutSymbols = EarningsEvent::query()
            ->whereIn('symbol', $watchlistSymbols)
            ->get()
            ->filter(fn ($e) => EarningsEvent::isInBlackout($e->symbol))
            ->pluck('symbol')
            ->unique()
            ->values()
            ->all();

        return [
            'watchlistSymbols' => $watchlistSymbols,
            'blackoutSymbols' => $blackoutSymbols,
        ];
    }
};
?>

<div class="flex flex-col gap-6 p-4" wire:init="loadMarketData">

    <div class="flex items-center justify-between">
        <div class="flex items-center gap-2">
            <flux:heading size="xl">Discover</flux:heading>
            <flux:tooltip content="Browse today's top movers and most-traded stocks. Add any symbol to your watchlist with one click.">
                <flux:icon.question-mark-circle class="size-4 cursor-help text-neutral-400" />
            </flux:tooltip>
        </div>
        <flux:button wire:click="refresh" size="sm" icon="arrow-path">Refresh</flux:button>
    </div>

    @if($error)
        <flux:callout color="red" icon="exclamation-triangle">
            {{ $error }}
            <flux:text size="sm" class="mt-1 text-neutral-500">Market data is only available during or after a trading day. Try again when the market is open or has recently closed.</flux:text>
        </flux:callout>
    @endif

    @if(session('added'))
        <flux:callout color="green" icon="check-circle">
            <strong>{{ session('added') }}</strong> added to your watchlist.
        </flux:callout>
    @endif

    {{-- Context note --}}
    <div id="tour-discover-context" class="rounded-xl border border-neutral-200 bg-neutral-50 px-4 py-3 text-sm text-neutral-600 dark:border-neutral-700 dark:bg-neutral-800/50 dark:text-neutral-400">
        <strong>How to use this page:</strong>
        <strong>Gainers/Losers</strong> show the biggest movers from yesterday's close — high volatility means more signal opportunities, but also more risk.
        <strong>Most Active</strong> shows the highest-volume stocks — volume often indicates institutional interest or a news event.
        Click <strong>Add</strong> on anything interesting to start tracking it.
    </div>

    {{-- Tab bar --}}
    <div class="flex gap-1 rounded-lg border border-neutral-200 bg-neutral-100 p-1 dark:border-neutral-700 dark:bg-neutral-800">
        <button
            wire:click="$set('tab', 'gainers')"
            class="flex flex-1 items-center justify-center gap-2 rounded-md px-3 py-1.5 text-sm font-medium transition-colors
                {{ $tab === 'gainers'
                    ? 'bg-white text-neutral-900 shadow-sm dark:bg-neutral-700 dark:text-white'
                    : 'text-neutral-500 hover:text-neutral-700 dark:text-neutral-400 dark:hover:text-neutral-200' }}"
        >
            <flux:icon.arrow-trending-up class="size-4 text-green-500" />
            Top Gainers
            @if(count($gainers))
                <span class="rounded-full bg-green-100 px-1.5 py-0.5 text-xs font-semibold text-green-700 dark:bg-green-900/40 dark:text-green-400">{{ count($gainers) }}</span>
            @endif
        </button>

        <button
            wire:click="$set('tab', 'losers')"
            class="flex flex-1 items-center justify-center gap-2 rounded-md px-3 py-1.5 text-sm font-medium transition-colors
                {{ $tab === 'losers'
                    ? 'bg-white text-neutral-900 shadow-sm dark:bg-neutral-700 dark:text-white'
                    : 'text-neutral-500 hover:text-neutral-700 dark:text-neutral-400 dark:hover:text-neutral-200' }}"
        >
            <flux:icon.arrow-trending-down class="size-4 text-red-500" />
            Top Losers
            @if(count($losers))
                <span class="rounded-full bg-red-100 px-1.5 py-0.5 text-xs font-semibold text-red-700 dark:bg-red-900/40 dark:text-red-400">{{ count($losers) }}</span>
            @endif
        </button>

        <button
            wire:click="$set('tab', 'actives')"
            class="flex flex-1 items-center justify-center gap-2 rounded-md px-3 py-1.5 text-sm font-medium transition-colors
                {{ $tab === 'actives'
                    ? 'bg-white text-neutral-900 shadow-sm dark:bg-neutral-700 dark:text-white'
                    : 'text-neutral-500 hover:text-neutral-700 dark:text-neutral-400 dark:hover:text-neutral-200' }}"
        >
            <flux:icon.fire class="size-4 text-orange-500" />
            Most Active
            @if(count($actives))
                <span class="rounded-full bg-orange-100 px-1.5 py-0.5 text-xs font-semibold text-orange-700 dark:bg-orange-900/40 dark:text-orange-400">{{ count($actives) }}</span>
            @endif
        </button>
    </div>

    {{-- Loading skeleton --}}
    @if($dataLoading)
        <div class="mt-4 rounded-xl border border-neutral-200 dark:border-neutral-700">
            <div class="space-y-3 p-4">
                @foreach(range(1, 8) as $i)
                    <div class="flex items-center gap-4">
                        <div class="h-4 w-16 animate-pulse rounded bg-neutral-200 dark:bg-neutral-700"></div>
                        <div class="h-4 w-20 animate-pulse rounded bg-neutral-200 dark:bg-neutral-700"></div>
                        <div class="h-4 w-16 animate-pulse rounded bg-neutral-200 dark:bg-neutral-700"></div>
                        <div class="h-4 w-16 animate-pulse rounded bg-neutral-200 dark:bg-neutral-700"></div>
                        <div class="ml-auto h-6 w-14 animate-pulse rounded bg-neutral-200 dark:bg-neutral-700"></div>
                    </div>
                @endforeach
            </div>
        </div>
    @else

    {{-- Tab panels --}}
    @if($tab === 'gainers')
        @if(empty($gainers))
            <x-discover-empty-state />
        @else
            <x-discover-table :rows="$gainers" :watchlist-symbols="$watchlistSymbols" :blackout-symbols="$blackoutSymbols" />
        @endif
    @elseif($tab === 'losers')
        @if(empty($losers))
            <x-discover-empty-state />
        @else
            <x-discover-table :rows="$losers" :watchlist-symbols="$watchlistSymbols" :blackout-symbols="$blackoutSymbols" />
        @endif
    @else
        @if(empty($actives))
            <x-discover-empty-state />
        @else
            <x-discover-table :rows="$actives" :watchlist-symbols="$watchlistSymbols" :blackout-symbols="$blackoutSymbols" />
        @endif
    @endif

    @endif {{-- end $dataLoading --}}

</div>
