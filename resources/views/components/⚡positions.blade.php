<?php

use App\Jobs\SyncPortfolioJob;
use App\Models\Position;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('Positions')] class extends Component
{
    public function syncNow(): void
    {
        SyncPortfolioJob::dispatch();
        session()->flash('message', 'Portfolio sync queued.');
    }

    public function with(): array
    {
        return [
            'positions' => Position::orderBy('symbol')->get(),
            'totalValue' => Position::sum('market_value'),
            'totalPl' => Position::sum('unrealized_pl'),
        ];
    }
};
?>

<div class="flex flex-col gap-6 p-4">
    <div id="tour-positions-heading" class="flex items-center justify-between">
        <div class="flex items-center gap-2">
            <flux:heading size="xl">Open Positions</flux:heading>
            <flux:tooltip content="Stocks you currently own shares in. A position opens when the bot buys, and closes when it sells all shares.">
                <flux:icon.question-mark-circle class="size-4 cursor-help text-neutral-400" />
            </flux:tooltip>
        </div>
        <div class="flex items-center gap-2">
            <flux:button size="xs" variant="ghost" icon="question-mark-circle" x-on:click="window.startTour('positions')">Take Tour</flux:button>
            <flux:button id="tour-sync-btn" wire:click="syncNow" size="sm" icon="arrow-path">Sync Now</flux:button>
        </div>
    </div>

    @if(session('message'))
        <flux:callout color="green" icon="check-circle">{{ session('message') }}</flux:callout>
    @endif

    @if($positions->isNotEmpty())
        <div class="grid grid-cols-2 gap-4 md:grid-cols-3">
            <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                <flux:text size="xs" class="uppercase tracking-wide text-neutral-500">Total Market Value</flux:text>
                <p class="mt-1 text-2xl font-semibold">${{ number_format($totalValue, 2) }}</p>
            </div>
            <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                <div class="flex items-center gap-1">
                    <flux:text size="xs" class="uppercase tracking-wide text-neutral-500">Unrealized P&L</flux:text>
                    <flux:tooltip content="Your profit or loss if you sold everything right now. 'Unrealized' means it's still on paper — not yet converted to cash.">
                        <flux:icon.question-mark-circle class="size-3 cursor-help text-neutral-400" />
                    </flux:tooltip>
                </div>
                <p class="mt-1 text-2xl font-semibold {{ $totalPl >= 0 ? 'text-green-600' : 'text-red-600' }}">
                    {{ $totalPl >= 0 ? '+' : '' }}${{ number_format($totalPl, 2) }}
                </p>
            </div>
            <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                <flux:text size="xs" class="uppercase tracking-wide text-neutral-500">Open Positions</flux:text>
                <p class="mt-1 text-2xl font-semibold">{{ $positions->count() }}</p>
            </div>
        </div>
    @endif

    <div id="tour-positions-table" class="rounded-xl border border-neutral-200 dark:border-neutral-700">
        @if($positions->isEmpty())
            <div class="px-4 py-12 text-center">
                <flux:text class="text-neutral-500">No open positions. The portfolio syncs automatically during market hours.</flux:text>
                <flux:text size="sm" class="mt-2 text-neutral-400">Once the bot executes a buy order, it will appear here.</flux:text>
            </div>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Symbol</flux:table.column>
                    <flux:table.column>Qty</flux:table.column>
                    <flux:table.column>
                        <flux:tooltip content="The average price you paid per share. If you bought at $100 and $120, your avg entry is $110.">
                            <span class="cursor-help">Avg Entry</span>
                        </flux:tooltip>
                    </flux:table.column>
                    <flux:table.column>Current Price</flux:table.column>
                    <flux:table.column>
                        <flux:tooltip content="Shares × current price. The current dollar value of this holding.">
                            <span class="cursor-help">Market Value</span>
                        </flux:tooltip>
                    </flux:table.column>
                    <flux:table.column>
                        <flux:tooltip content="Profit or loss if you sold right now. Not yet real — could still go up or down.">
                            <span class="cursor-help">Unrealized P&L</span>
                        </flux:tooltip>
                    </flux:table.column>
                    <flux:table.column>Last Sync</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach($positions as $position)
                        <flux:table.row :key="$position->id">
                            <flux:table.cell class="font-mono font-semibold">{{ $position->symbol }}</flux:table.cell>
                            <flux:table.cell>{{ number_format($position->qty, 4) }}</flux:table.cell>
                            <flux:table.cell>${{ number_format($position->avg_entry_price, 2) }}</flux:table.cell>
                            <flux:table.cell>${{ $position->current_price ? number_format($position->current_price, 2) : '—' }}</flux:table.cell>
                            <flux:table.cell>${{ $position->market_value ? number_format($position->market_value, 2) : '—' }}</flux:table.cell>
                            <flux:table.cell class="{{ $position->isProfit() ? 'text-green-600' : 'text-red-600' }}">
                                @if($position->unrealized_pl !== null)
                                    {{ $position->isProfit() ? '+' : '' }}${{ number_format($position->unrealized_pl, 2) }}
                                    <span class="text-xs opacity-70">({{ number_format($position->unrealizedPlPercent(), 2) }}%)</span>
                                @else
                                    —
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="text-sm text-neutral-500">
                                {{ $position->synced_at?->diffForHumans() ?? 'Never' }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>
</div>
