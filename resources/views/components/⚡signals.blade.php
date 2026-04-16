<?php

use App\Enums\SignalAction;
use App\Models\Signal;
use App\Models\Stock;
use App\Services\SignalService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Signals')] class extends Component
{
    use WithPagination;

    public string $filterSymbol = '';

    public string $filterAction = '';

    public function runSignalNow(int $stockId, SignalService $signalService): void
    {
        $stock = Stock::findOrFail($stockId);
        $signalService->generateSignal($stock->symbol);
        session()->flash('message', "Signal generated for {$stock->symbol}.");
    }

    public function with(): array
    {
        $query = Signal::with('trade')->orderByDesc('created_at');

        if ($this->filterSymbol) {
            $query->where('symbol', strtoupper(trim($this->filterSymbol)));
        }

        if ($this->filterAction) {
            $query->where('action', $this->filterAction);
        }

        return [
            'signals' => $query->paginate(30),
            'activeStocks' => Stock::active()->orderBy('symbol')->get(),
        ];
    }
};
?>

<div class="flex flex-col gap-6 p-4">
    <div id="tour-signals-heading" class="flex items-center justify-between">
        <div class="flex items-center gap-2">
            <flux:heading size="xl">Signals</flux:heading>
            <flux:tooltip content="Signals are the bot's recommendations: Buy, Sell, or Hold. Generated every 5 minutes per active symbol during market hours.">
                <flux:icon.question-mark-circle class="size-4 cursor-help text-neutral-400" />
            </flux:tooltip>
        </div>
        <flux:button size="xs" variant="ghost" icon="question-mark-circle" x-on:click="window.startTour('signals')">Take Tour</flux:button>
    </div>

    @if(session('message'))
        <flux:callout color="green" icon="check-circle">{{ session('message') }}</flux:callout>
    @endif

    {{-- Strategy note --}}
    <div id="tour-strategy-note" class="rounded-xl border border-neutral-200 bg-neutral-50 px-4 py-3 dark:border-neutral-700 dark:bg-neutral-800/50">
        <flux:text size="sm" class="text-neutral-600 dark:text-neutral-400">
            <strong>Strategy:</strong> {{ config('alpaca.strategy') === 'ai' ? 'AI (Claude Haiku)' : 'SMA Crossover (5-day vs 20-day)' }}
            @if(config('alpaca.strategy') === 'sma_crossover')
                — Buy when the 5-day average crosses above the 20-day average; Sell when it crosses below. A basic but time-tested trend-following approach.
            @else
                — Claude Haiku analyzes recent price data and returns a signal with a confidence score and reasoning.
            @endif
        </flux:text>
    </div>

    @if($activeStocks->isNotEmpty())
        <div id="tour-manual-triggers" class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <div class="flex items-center gap-1 mb-3">
                <flux:text size="sm" class="font-medium">Run signal manually:</flux:text>
                <flux:tooltip content="Trigger the signal logic right now for a specific stock, outside the normal 5-minute schedule. Useful for testing.">
                    <flux:icon.question-mark-circle class="size-3 cursor-help text-neutral-400" />
                </flux:tooltip>
            </div>
            <div class="flex flex-wrap gap-2">
                @foreach($activeStocks as $stock)
                    <flux:button wire:click="runSignalNow({{ $stock->id }})" size="xs" variant="outline">
                        {{ $stock->symbol }}
                    </flux:button>
                @endforeach
            </div>
        </div>
    @endif

    <div class="flex flex-wrap gap-3">
        <flux:input wire:model.live="filterSymbol" placeholder="Filter by symbol" size="sm" class="w-36" />
        <flux:select wire:model.live="filterAction" size="sm" class="w-28">
            <flux:select.option value="">All actions</flux:select.option>
            <flux:select.option value="buy">Buy</flux:select.option>
            <flux:select.option value="sell">Sell</flux:select.option>
            <flux:select.option value="hold">Hold</flux:select.option>
        </flux:select>
    </div>

    <div id="tour-signals-table" class="rounded-xl border border-neutral-200 dark:border-neutral-700">
        @if($signals->isEmpty())
            <div class="px-4 py-12 text-center">
                <flux:text class="text-neutral-500">No signals yet. The bot generates signals every 5 minutes during market hours.</flux:text>
                <flux:text size="sm" class="mt-2 text-neutral-400">Make sure you have at least one Active symbol on the Watchlist.</flux:text>
            </div>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Symbol</flux:table.column>
                    <flux:table.column>
                        <flux:tooltip content="Buy = upward trend detected. Sell = downward trend detected. Hold = no clear signal.">
                            <span class="cursor-help">Action</span>
                        </flux:tooltip>
                    </flux:table.column>
                    <flux:table.column>Price at Signal</flux:table.column>
                    <flux:table.column>
                        <flux:tooltip content="How certain the AI strategy is (0–100%). Only populated when using the AI strategy — SMA crossover is rule-based so confidence is always N/A.">
                            <span class="cursor-help">Confidence</span>
                        </flux:tooltip>
                    </flux:table.column>
                    <flux:table.column>Reason</flux:table.column>
                    <flux:table.column>
                        <flux:tooltip content="Whether this signal triggered an actual trade. If Auto-Trading is Off, all signals will show Pending.">
                            <span class="cursor-help">Executed</span>
                        </flux:tooltip>
                    </flux:table.column>
                    <flux:table.column>Generated</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach($signals as $signal)
                        <flux:table.row :key="$signal->id">
                            <flux:table.cell class="font-mono font-semibold">{{ $signal->symbol }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge :color="$signal->action->color()" size="sm">{{ $signal->action->label() }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>
                                {{ $signal->price_at_signal ? '$'.number_format($signal->price_at_signal, 2) : '—' }}
                            </flux:table.cell>
                            <flux:table.cell>
                                @if($signal->confidence !== null)
                                    {{ number_format($signal->confidence * 100, 0) }}%
                                @else
                                    —
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="max-w-xs truncate text-sm text-neutral-500">
                                {{ $signal->reason ?? '—' }}
                            </flux:table.cell>
                            <flux:table.cell>
                                @if($signal->action === SignalAction::Hold)
                                    <flux:badge color="zinc" size="sm">N/A</flux:badge>
                                @elseif($signal->executed)
                                    <flux:badge color="green" size="sm">Yes</flux:badge>
                                @else
                                    <flux:badge color="yellow" size="sm">Pending</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="text-sm text-neutral-500">
                                {{ $signal->created_at->diffForHumans() }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
            <div class="border-t border-neutral-200 px-4 py-3 dark:border-neutral-700">
                {{ $signals->links() }}
            </div>
        @endif
    </div>
</div>
