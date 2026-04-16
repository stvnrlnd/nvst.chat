<?php

use App\Models\EarningsEvent;
use App\Models\NewsArticle;
use App\Models\Stock;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('Watchlist')] class extends Component
{
    #[Validate('required|string|max:10|regex:/^[A-Z]+$/')]
    public string $symbol = '';

    public string $name = '';

    public string $notes = '';

    public bool $showAddForm = false;

    public function addStock(): void
    {
        $this->validateOnly('symbol');

        $this->symbol = strtoupper(trim($this->symbol));

        Stock::updateOrCreate(
            ['symbol' => $this->symbol],
            [
                'name' => $this->name ?: null,
                'notes' => $this->notes ?: null,
                'is_active' => true,
            ],
        );

        $this->reset(['symbol', 'name', 'notes', 'showAddForm']);
    }

    public function toggleActive(int $id): void
    {
        $stock = Stock::findOrFail($id);
        $stock->update(['is_active' => ! $stock->is_active]);
    }

    public function removeStock(int $id): void
    {
        Stock::findOrFail($id)->delete();
    }

    public function with(): array
    {
        $stocks = Stock::orderBy('symbol')->get();

        // Build a map of symbol => next earnings date + blackout status
        $earningsMap = EarningsEvent::query()
            ->whereIn('symbol', $stocks->pluck('symbol'))
            ->whereDate('report_date', '>=', now()->subDay())
            ->orderBy('report_date')
            ->get()
            ->groupBy('symbol')
            ->map(fn ($events) => $events->first());

        // Build sentiment map: symbol => ['score' => float|null, 'label' => string, 'articles' => collection]
        $sentimentMap = [];

        foreach ($stocks->pluck('symbol') as $sym) {
            $score = NewsArticle::aggregateSentiment($sym, 5);
            $sentimentMap[$sym] = [
                'score' => $score,
                'label' => NewsArticle::sentimentLabel($score),
                'articles' => NewsArticle::query()
                    ->where('symbol', $sym)
                    ->orderByDesc('published_at')
                    ->limit(3)
                    ->get(),
            ];
        }

        return [
            'stocks' => $stocks,
            'earningsMap' => $earningsMap,
            'sentimentMap' => $sentimentMap,
        ];
    }
};
?>

<div class="flex flex-col gap-6 p-4">
    <div id="tour-watchlist-heading" class="flex items-center justify-between">
        <div class="flex items-center gap-2">
            <flux:heading size="xl">Watchlist</flux:heading>
            <flux:tooltip content="The bot generates Buy/Sell/Hold signals for every Active symbol here, every 5 minutes during market hours.">
                <flux:icon.question-mark-circle class="size-4 cursor-help text-neutral-400" />
            </flux:tooltip>
        </div>
        <div class="flex items-center gap-2">
            <flux:button size="xs" variant="ghost" icon="question-mark-circle" x-on:click="window.startTour('watchlist')">Take Tour</flux:button>
            <flux:button id="tour-add-symbol" wire:click="$toggle('showAddForm')" size="sm" icon="plus">Add Symbol</flux:button>
        </div>
    </div>

    @if($showAddForm)
        <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <flux:heading size="sm" class="mb-4">Add Symbol</flux:heading>
            <form wire:submit="addStock" class="flex flex-col gap-3">
                <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                    <div>
                        <flux:input wire:model="symbol" label="Ticker Symbol" placeholder="AAPL" required />
                        <flux:text size="xs" class="mt-1 text-neutral-400">The exchange code — e.g. AAPL (Apple), MSFT (Microsoft), SPY (S&amp;P 500 ETF)</flux:text>
                    </div>
                    <flux:input wire:model="name" label="Company Name" placeholder="Apple Inc." />
                    <flux:input wire:model="notes" label="Notes" placeholder="Optional" />
                </div>
                <flux:error name="symbol" />
                <div class="flex gap-2">
                    <flux:button type="submit" size="sm">Add</flux:button>
                    <flux:button wire:click="$set('showAddForm', false)" size="sm" variant="ghost">Cancel</flux:button>
                </div>
            </form>
        </div>
    @endif

    <div id="tour-watchlist-table" class="rounded-xl border border-neutral-200 dark:border-neutral-700">
        @if($stocks->isEmpty())
            <div class="px-4 py-12 text-center">
                <flux:text class="text-neutral-500">No stocks yet. Add a ticker symbol to start tracking.</flux:text>
                <flux:text size="sm" class="mt-2 text-neutral-400">Try starting with a few well-known ones: AAPL, MSFT, or SPY.</flux:text>
            </div>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>
                        <flux:tooltip content="The stock's unique identifier on the exchange (e.g. AAPL = Apple).">
                            <span class="cursor-help">Symbol</span>
                        </flux:tooltip>
                    </flux:table.column>
                    <flux:table.column>Name</flux:table.column>
                    <flux:table.column>Notes</flux:table.column>
                    <flux:table.column>
                        <flux:tooltip content="Active = bot generates signals every 5 min. Paused = bot skips this symbol.">
                            <span class="cursor-help">Status</span>
                        </flux:tooltip>
                    </flux:table.column>
                    <flux:table.column>
                        <flux:tooltip content="Next known earnings report date. Trades are blocked 2 days before and 1 day after to avoid earnings volatility.">
                            <span class="cursor-help">Earnings</span>
                        </flux:tooltip>
                    </flux:table.column>
                    <flux:table.column>
                        <flux:tooltip content="Aggregate sentiment from the last 5 news articles via Polygon. Strongly negative sentiment suppresses BUY signals.">
                            <span class="cursor-help">Sentiment</span>
                        </flux:tooltip>
                    </flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach($stocks as $stock)
                        @php
                            $earningsEvent = $earningsMap[$stock->symbol] ?? null;
                            $inBlackout = $earningsEvent && \App\Models\EarningsEvent::isInBlackout($stock->symbol);
                            $sentiment = $sentimentMap[$stock->symbol] ?? ['score' => null, 'label' => 'unknown', 'articles' => collect()];
                            $sentimentColor = match($sentiment['label']) {
                                'positive' => 'green',
                                'negative' => 'red',
                                'neutral'  => 'zinc',
                                default    => 'zinc',
                            };
                        @endphp
                        <flux:table.row :key="$stock->id">
                            <flux:table.cell class="font-mono font-semibold">{{ $stock->symbol }}</flux:table.cell>
                            <flux:table.cell>{{ $stock->name ?? '—' }}</flux:table.cell>
                            <flux:table.cell class="text-neutral-500">{{ $stock->notes ?? '—' }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge :color="$stock->is_active ? 'green' : 'zinc'" size="sm">
                                    {{ $stock->is_active ? 'Active' : 'Paused' }}
                                </flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>
                                @if($inBlackout)
                                    <flux:tooltip :content="'Earnings on '.$earningsEvent->report_date->toFormattedDateString().'. Trades are blocked around this date to avoid volatility.'">
                                        <flux:badge color="yellow" size="sm" icon="exclamation-triangle">
                                            Blackout {{ $earningsEvent->report_date->toFormattedDateString() }}
                                        </flux:badge>
                                    </flux:tooltip>
                                @elseif($earningsEvent)
                                    <flux:text size="sm" class="text-neutral-500">{{ $earningsEvent->report_date->toFormattedDateString() }}</flux:text>
                                @else
                                    <flux:text size="sm" class="text-neutral-400">—</flux:text>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                @if($sentiment['score'] !== null)
                                    @php
                                        $articleTitles = $sentiment['articles']->map(fn($a) => '• '.$a->title)->implode("\n");
                                        $tooltipText = $articleTitles ?: 'No recent articles.';
                                    @endphp
                                    <flux:tooltip :content="$tooltipText" position="left">
                                        <flux:badge :color="$sentimentColor" size="sm" class="cursor-help">
                                            {{ ucfirst($sentiment['label']) }}
                                            ({{ number_format($sentiment['score'], 2) }})
                                        </flux:badge>
                                    </flux:tooltip>
                                @else
                                    <flux:text size="sm" class="text-neutral-400">—</flux:text>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex gap-2">
                                    <flux:button wire:click="toggleActive({{ $stock->id }})" size="xs" variant="ghost">
                                        {{ $stock->is_active ? 'Pause' : 'Resume' }}
                                    </flux:button>
                                    <flux:button wire:click="removeStock({{ $stock->id }})" wire:confirm="Remove {{ $stock->symbol }} from watchlist?" size="xs" variant="danger">
                                        Remove
                                    </flux:button>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>
</div>
