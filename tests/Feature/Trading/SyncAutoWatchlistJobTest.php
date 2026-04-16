<?php

use App\Jobs\SyncAutoWatchlistJob;
use App\Models\Stock;
use App\Services\AlpacaService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->alpacaMock = $this->mock(AlpacaService::class);

    config([
        'alpaca.auto_watchlist_enabled' => true,
        'alpaca.auto_watchlist_size' => 10,
        'alpaca.auto_watchlist_stale_days' => 7,
    ]);
});

function mostActives(array $symbols): array
{
    return ['most_actives' => array_map(fn ($s) => ['symbol' => $s], $symbols)];
}

function topMovers(array $gainers = [], array $losers = []): array
{
    return [
        'gainers' => array_map(fn ($s) => ['symbol' => $s], $gainers),
        'losers' => array_map(fn ($s) => ['symbol' => $s], $losers),
    ];
}

// ── Core behaviour ────────────────────────────────────────────────────────────

it('adds new auto symbols from most-actives', function () {
    $this->alpacaMock->allows('getMostActives')->andReturn(mostActives(['AAPL', 'MSFT']));
    $this->alpacaMock->allows('getTopMovers')->andReturn(topMovers());

    (new SyncAutoWatchlistJob)->handle($this->alpacaMock);

    expect(Stock::count())->toBe(2);
    expect(Stock::where('symbol', 'AAPL')->where('source', 'auto')->where('is_active', true)->exists())->toBeTrue();
    expect(Stock::where('symbol', 'MSFT')->where('source', 'auto')->where('is_active', true)->exists())->toBeTrue();
});

it('adds new auto symbols from top gainers', function () {
    $this->alpacaMock->allows('getMostActives')->andReturn(mostActives([]));
    $this->alpacaMock->allows('getTopMovers')->andReturn(topMovers(['NVDA', 'AMD']));

    (new SyncAutoWatchlistJob)->handle($this->alpacaMock);

    expect(Stock::where('symbol', 'NVDA')->where('source', 'auto')->exists())->toBeTrue();
    expect(Stock::where('symbol', 'AMD')->where('source', 'auto')->exists())->toBeTrue();
});

it('deduplicates symbols appearing in both feeds', function () {
    $this->alpacaMock->allows('getMostActives')->andReturn(mostActives(['AAPL', 'MSFT']));
    $this->alpacaMock->allows('getTopMovers')->andReturn(topMovers(['MSFT', 'GOOGL'])); // MSFT in both

    (new SyncAutoWatchlistJob)->handle($this->alpacaMock);

    expect(Stock::count())->toBe(3);
    expect(Stock::where('symbol', 'MSFT')->count())->toBe(1);
});

it('updates last_seen_at and keeps auto symbols active on re-sync', function () {
    $stock = Stock::factory()->create(['symbol' => 'AAPL', 'source' => 'auto', 'is_active' => true]);

    $this->alpacaMock->allows('getMostActives')->andReturn(mostActives(['AAPL']));
    $this->alpacaMock->allows('getTopMovers')->andReturn(topMovers());

    (new SyncAutoWatchlistJob)->handle($this->alpacaMock);

    $stock->refresh();
    expect($stock->is_active)->toBeTrue()
        ->and($stock->last_seen_at)->not->toBeNull();

    expect(Stock::count())->toBe(1); // No duplicate created
});

it('never modifies a manually-added stock source or active status', function () {
    Stock::factory()->create(['symbol' => 'AAPL', 'source' => 'manual', 'is_active' => true]);

    $this->alpacaMock->allows('getMostActives')->andReturn(mostActives(['AAPL']));
    $this->alpacaMock->allows('getTopMovers')->andReturn(topMovers());

    (new SyncAutoWatchlistJob)->handle($this->alpacaMock);

    $stock = Stock::where('symbol', 'AAPL')->first();
    expect($stock->source)->toBe('manual')
        ->and($stock->is_active)->toBeTrue();

    expect(Stock::count())->toBe(1);
});

it('deactivates stale auto symbols not seen within stale_days', function () {
    // Stale: last seen 10 days ago
    $stale = Stock::factory()->create([
        'symbol' => 'OLD1',
        'source' => 'auto',
        'is_active' => true,
        'last_seen_at' => now()->subDays(10),
    ]);

    $this->alpacaMock->allows('getMostActives')->andReturn(mostActives(['AAPL']));
    $this->alpacaMock->allows('getTopMovers')->andReturn(topMovers());

    (new SyncAutoWatchlistJob)->handle($this->alpacaMock);

    $stale->refresh();
    expect($stale->is_active)->toBeFalse();
});

it('does not deactivate stale manual symbols', function () {
    // Manual stock with no last_seen_at — would match stale scope if not filtered
    $manual = Stock::factory()->create([
        'symbol' => 'AAPL',
        'source' => 'manual',
        'is_active' => true,
        'last_seen_at' => null,
    ]);

    $this->alpacaMock->allows('getMostActives')->andReturn(mostActives(['MSFT']));
    $this->alpacaMock->allows('getTopMovers')->andReturn(topMovers());

    (new SyncAutoWatchlistJob)->handle($this->alpacaMock);

    $manual->refresh();
    expect($manual->is_active)->toBeTrue();
});

it('does not deactivate a fresh auto symbol seen today', function () {
    Stock::factory()->create([
        'symbol' => 'AAPL',
        'source' => 'auto',
        'is_active' => true,
        'last_seen_at' => now()->subHours(2),
    ]);

    $this->alpacaMock->allows('getMostActives')->andReturn(mostActives(['MSFT']));
    $this->alpacaMock->allows('getTopMovers')->andReturn(topMovers());

    (new SyncAutoWatchlistJob)->handle($this->alpacaMock);

    expect(Stock::where('symbol', 'AAPL')->value('is_active'))->toBeTrue();
});

// ── Resilience ────────────────────────────────────────────────────────────────

it('still runs when most-actives API fails but top-movers succeeds', function () {
    $this->alpacaMock->allows('getMostActives')->andThrow(new RuntimeException('timeout'));
    $this->alpacaMock->allows('getTopMovers')->andReturn(topMovers(['NVDA']));

    (new SyncAutoWatchlistJob)->handle($this->alpacaMock);

    expect(Stock::where('symbol', 'NVDA')->exists())->toBeTrue();
});

it('still runs when top-movers API fails but most-actives succeeds', function () {
    $this->alpacaMock->allows('getMostActives')->andReturn(mostActives(['AAPL']));
    $this->alpacaMock->allows('getTopMovers')->andThrow(new RuntimeException('timeout'));

    (new SyncAutoWatchlistJob)->handle($this->alpacaMock);

    expect(Stock::where('symbol', 'AAPL')->exists())->toBeTrue();
});

it('skips the entire sync when both APIs fail', function () {
    $this->alpacaMock->allows('getMostActives')->andThrow(new RuntimeException('down'));
    $this->alpacaMock->allows('getTopMovers')->andThrow(new RuntimeException('down'));

    (new SyncAutoWatchlistJob)->handle($this->alpacaMock);

    expect(Stock::count())->toBe(0);
});

it('does nothing when auto_watchlist_enabled is false', function () {
    config(['alpaca.auto_watchlist_enabled' => false]);

    $this->alpacaMock->shouldNotReceive('getMostActives');
    $this->alpacaMock->shouldNotReceive('getTopMovers');

    (new SyncAutoWatchlistJob)->handle($this->alpacaMock);

    expect(Stock::count())->toBe(0);
});
