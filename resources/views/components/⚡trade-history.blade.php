<?php

use App\Models\Trade;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Trade History')] class extends Component
{
    use WithPagination;

    public string $filterSymbol = '';

    public string $filterSide = '';

    public string $filterStatus = '';

    public function updatingFilterSymbol(): void
    {
        $this->resetPage();
    }

    public function updatingSide(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        $query = Trade::with('signal')->orderByDesc('created_at');

        if ($this->filterSymbol) {
            $query->where('symbol', strtoupper(trim($this->filterSymbol)));
        }

        if ($this->filterSide) {
            $query->where('side', $this->filterSide);
        }

        if ($this->filterStatus) {
            $query->where('status', $this->filterStatus);
        }

        return [
            'trades' => $query->paginate(25),
        ];
    }
};
?>

<div class="flex flex-col gap-6 p-4">
    <div id="tour-trades-heading" class="flex items-center justify-between">
        <div class="flex items-center gap-2">
            <flux:heading size="xl">Trade History</flux:heading>
            <flux:tooltip content="Every order the bot has submitted to Alpaca. A signal only becomes a trade when Auto-Trading is On.">
                <flux:icon.question-mark-circle class="size-4 cursor-help text-neutral-400" />
            </flux:tooltip>
        </div>
        <flux:button size="xs" variant="ghost" icon="question-mark-circle" x-on:click="window.startTour('trades')">Take Tour</flux:button>
    </div>

    <div class="flex flex-wrap gap-3">
        <flux:input wire:model.live="filterSymbol" placeholder="Filter by symbol" size="sm" class="w-36" />
        <flux:select wire:model.live="filterSide" size="sm" class="w-28">
            <flux:select.option value="">All sides</flux:select.option>
            <flux:select.option value="buy">Buy</flux:select.option>
            <flux:select.option value="sell">Sell</flux:select.option>
        </flux:select>
        <flux:select wire:model.live="filterStatus" size="sm" class="w-36">
            <flux:select.option value="">All statuses</flux:select.option>
            <flux:select.option value="pending">Pending</flux:select.option>
            <flux:select.option value="filled">Filled</flux:select.option>
            <flux:select.option value="canceled">Canceled</flux:select.option>
            <flux:select.option value="rejected">Rejected</flux:select.option>
        </flux:select>
    </div>

    <div id="tour-trades-table" class="rounded-xl border border-neutral-200 dark:border-neutral-700">
        @if($trades->isEmpty())
            <div class="px-4 py-12 text-center">
                <flux:text class="text-neutral-500">No trades found.</flux:text>
                <flux:text size="sm" class="mt-2 text-neutral-400">Trades appear here once Auto-Trading is On and a Buy or Sell signal fires.</flux:text>
            </div>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Symbol</flux:table.column>
                    <flux:table.column>
                        <flux:tooltip content="Buy = shares were purchased. Sell = shares were sold.">
                            <span class="cursor-help">Side</span>
                        </flux:tooltip>
                    </flux:table.column>
                    <flux:table.column>Qty</flux:table.column>
                    <flux:table.column>
                        <flux:tooltip content="The actual price the order was executed at. The bot uses market orders, so this is the best available price at the moment.">
                            <span class="cursor-help">Fill Price</span>
                        </flux:tooltip>
                    </flux:table.column>
                    <flux:table.column>Total</flux:table.column>
                    <flux:table.column>
                        <flux:tooltip content="Pending = submitted, waiting. Filled = completed. Canceled/Rejected = didn't execute.">
                            <span class="cursor-help">Status</span>
                        </flux:tooltip>
                    </flux:table.column>
                    <flux:table.column>Submitted</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach($trades as $trade)
                        <flux:table.row :key="$trade->id">
                            <flux:table.cell class="font-mono font-semibold">{{ $trade->symbol }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge :color="$trade->side === \App\Enums\TradeSide::Buy ? 'green' : 'red'" size="sm">
                                    {{ strtoupper($trade->side->value) }}
                                </flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>{{ number_format($trade->qty, 4) }}</flux:table.cell>
                            <flux:table.cell>
                                {{ $trade->filled_avg_price ? '$'.number_format($trade->filled_avg_price, 2) : '—' }}
                            </flux:table.cell>
                            <flux:table.cell>
                                {{ $trade->totalValue() > 0 ? '$'.number_format($trade->totalValue(), 2) : '—' }}
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:badge :color="$trade->status->color()" size="sm">{{ $trade->status->value }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell class="text-sm text-neutral-500">
                                {{ $trade->submitted_at?->format('M j, g:ia') ?? $trade->created_at->format('M j, g:ia') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
            <div class="border-t border-neutral-200 px-4 py-3 dark:border-neutral-700">
                {{ $trades->links() }}
            </div>
        @endif
    </div>
</div>
