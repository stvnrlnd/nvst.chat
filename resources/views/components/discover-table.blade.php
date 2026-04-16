@props(['rows', 'watchlistSymbols', 'blackoutSymbols' => [], 'changeType' => 'neutral'])

<div class="mt-4 rounded-xl border border-neutral-200 dark:border-neutral-700">
    <flux:table>
        <flux:table.columns>
            <flux:table.column>Symbol</flux:table.column>
            <flux:table.column>
                <flux:tooltip content="Earnings blackout: trades are suppressed 2 days before and 1 day after a report to avoid volatility. Only shown for symbols already on your watchlist.">
                    <span class="cursor-help">Earnings</span>
                </flux:tooltip>
            </flux:table.column>
            <flux:table.column>
                <flux:tooltip content="The last traded price for this stock.">
                    <span class="cursor-help">Price</span>
                </flux:tooltip>
            </flux:table.column>
            <flux:table.column>
                <flux:tooltip content="Dollar change from yesterday's closing price.">
                    <span class="cursor-help">Change</span>
                </flux:tooltip>
            </flux:table.column>
            <flux:table.column>
                <flux:tooltip content="Percentage change from yesterday's close. Bigger moves = more signal opportunities, but also more risk.">
                    <span class="cursor-help">% Change</span>
                </flux:tooltip>
            </flux:table.column>
            <flux:table.column>
                <flux:tooltip content="Number of shares traded so far today. High volume often means news, earnings, or institutional activity.">
                    <span class="cursor-help">Volume</span>
                </flux:tooltip>
            </flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach($rows as $row)
                @php
                    $symbol = strtoupper($row['symbol'] ?? '');
                    $price = isset($row['price']) ? (float) $row['price'] : null;
                    $change = isset($row['change']) ? (float) $row['change'] : null;
                    $pct = isset($row['percent_change']) ? (float) $row['percent_change'] : null;
                    $volume = $row['volume'] ?? null;
                    $onWatchlist = in_array($symbol, $watchlistSymbols);
                    $inBlackout = in_array($symbol, $blackoutSymbols);
                    $isPositive = $change !== null && $change >= 0;
                @endphp
                <flux:table.row :key="$symbol">
                    <flux:table.cell class="font-mono font-semibold">{{ $symbol }}</flux:table.cell>
                    <flux:table.cell>
                        @if($inBlackout)
                            <flux:badge color="yellow" size="sm" icon="exclamation-triangle">Blackout</flux:badge>
                        @else
                            <span class="text-neutral-400">—</span>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        {{ $price !== null ? '$'.number_format($price, 2) : '—' }}
                    </flux:table.cell>
                    <flux:table.cell class="{{ $isPositive ? 'text-green-600' : 'text-red-600' }}">
                        @if($change !== null)
                            {{ $isPositive ? '+' : '' }}${{ number_format(abs($change), 2) }}
                        @else
                            —
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        @if($pct !== null)
                            <flux:badge color="{{ $isPositive ? 'green' : 'red' }}" size="sm">
                                {{ $isPositive ? '+' : '' }}{{ number_format($pct, 2) }}%
                            </flux:badge>
                        @else
                            —
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="text-neutral-500">
                        @if($volume !== null)
                            {{ number_format((int) $volume) }}
                        @else
                            —
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        @if($onWatchlist)
                            <flux:badge color="zinc" size="sm" icon="check">Watching</flux:badge>
                        @else
                            <flux:button wire:click="addToWatchlist('{{ $symbol }}')" size="xs" variant="outline" icon="plus">
                                Add
                            </flux:button>
                        @endif
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
</div>
