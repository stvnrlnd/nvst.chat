<?php

use App\Models\ScreenerCandidate;
use App\Models\Stock;
use App\Services\AlpacaService;
use App\Services\MarketDataService;
use App\Services\ScreenerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->alpacaMock = $this->mock(AlpacaService::class);
    $this->marketData = new MarketDataService($this->alpacaMock);
    $this->service = new ScreenerService($this->alpacaMock, $this->marketData);

    config([
        'alpaca.screener_min_price' => 5.0,
        'alpaca.screener_min_atr_pct' => 1.0,
        'alpaca.screener_max_atr_pct' => 8.0,
        'alpaca.screener_use_universe' => false,
    ]);
});

// ── getBarsBulk ───────────────────────────────────────────────────────────────

it('scores symbols using bulk bar data returned keyed by symbol', function () {
    Stock::factory()->create(['symbol' => 'AAPL']);
    Stock::factory()->create(['symbol' => 'MSFT']);

    $bars = makeBars(30, 40.0, 0.33, 1.5);

    $this->alpacaMock->allows('getBarsBulk')
        ->with(['AAPL', 'MSFT'], 30)
        ->andReturn(['AAPL' => $bars, 'MSFT' => $bars]);

    $results = $this->service->run(Carbon::today());

    expect($results)->toHaveCount(2);

    foreach ($results as $candidate) {
        expect($candidate->disqualified)->toBeFalse()
            ->and($candidate->score)->toBeGreaterThan(50.0);
    }
});

it('skips a symbol silently when the bulk response contains no bars for it', function () {
    Stock::factory()->create(['symbol' => 'AAPL']);
    Stock::factory()->create(['symbol' => 'MSFT']);

    $bars = makeBars(30, 40.0, 0.33, 1.5);

    // Bulk response only returns AAPL — MSFT missing (e.g. not yet trading)
    $this->alpacaMock->allows('getBarsBulk')
        ->with(['AAPL', 'MSFT'], 30)
        ->andReturn(['AAPL' => $bars]);

    $results = $this->service->run(Carbon::today());

    expect($results)->toHaveCount(1)
        ->and($results[0]->symbol)->toBe('AAPL');

    expect(ScreenerCandidate::where('symbol', 'MSFT')->count())->toBe(0);
});

it('falls back to per-symbol fetching when the bulk endpoint throws', function () {
    Stock::factory()->create(['symbol' => 'AAPL']);

    $bars = makeBars(30, 40.0, 0.33, 1.5);

    $this->alpacaMock->allows('getBarsBulk')->andThrow(new RuntimeException('bulk endpoint unavailable'));
    $this->alpacaMock->allows('getBars')->with('AAPL', 30)->andReturn($bars);

    $results = $this->service->run(Carbon::today());

    expect($results)->toHaveCount(1)
        ->and($results[0]->symbol)->toBe('AAPL')
        ->and($results[0]->disqualified)->toBeFalse();
});

// ── Universe mode ─────────────────────────────────────────────────────────────

it('merges watchlist with most-actives when universe mode is on', function () {
    config(['alpaca.screener_use_universe' => true, 'alpaca.screener_universe_size' => 3]);

    Stock::factory()->create(['symbol' => 'AAPL']); // watchlist

    $bars = makeBars(30, 40.0, 0.33, 1.5);

    // Most actives returns MSFT and GOOGL (not on watchlist)
    $this->alpacaMock->allows('getMostActives')->with(3)->andReturn([
        'most_actives' => [
            ['symbol' => 'MSFT'],
            ['symbol' => 'GOOGL'],
        ],
    ]);

    // Bulk fetch for all 3 merged symbols
    $this->alpacaMock->allows('getBarsBulk')
        ->with(\Mockery::on(fn ($s) => count($s) === 3 && in_array('AAPL', $s) && in_array('MSFT', $s) && in_array('GOOGL', $s)), 30)
        ->andReturn(['AAPL' => $bars, 'MSFT' => $bars, 'GOOGL' => $bars]);

    $results = $this->service->run(Carbon::today());

    $symbols = array_column($results, 'symbol');
    expect($symbols)->toContain('AAPL')
        ->toContain('MSFT')
        ->toContain('GOOGL');
});

it('deduplicates symbols when watchlist and universe overlap', function () {
    config(['alpaca.screener_use_universe' => true, 'alpaca.screener_universe_size' => 3]);

    Stock::factory()->create(['symbol' => 'AAPL']); // also in most-actives

    $bars = makeBars(30, 40.0, 0.33, 1.5);

    $this->alpacaMock->allows('getMostActives')->with(3)->andReturn([
        'most_actives' => [
            ['symbol' => 'AAPL'], // duplicate
            ['symbol' => 'MSFT'],
        ],
    ]);

    $this->alpacaMock->allows('getBarsBulk')
        ->with(\Mockery::on(fn ($s) => count($s) === 2), 30) // only 2 unique symbols
        ->andReturn(['AAPL' => $bars, 'MSFT' => $bars]);

    $results = $this->service->run(Carbon::today());

    expect($results)->toHaveCount(2);
    expect(ScreenerCandidate::where('symbol', 'AAPL')->count())->toBe(1);
});

it('falls back to watchlist only when the most-actives API fails', function () {
    config(['alpaca.screener_use_universe' => true, 'alpaca.screener_universe_size' => 10]);

    Stock::factory()->create(['symbol' => 'AAPL']);

    $bars = makeBars(30, 40.0, 0.33, 1.5);

    $this->alpacaMock->allows('getMostActives')->andThrow(new RuntimeException('API down'));
    $this->alpacaMock->allows('getBarsBulk')
        ->with(['AAPL'], 30)
        ->andReturn(['AAPL' => $bars]);

    $results = $this->service->run(Carbon::today());

    // Still scores the watchlist symbol despite universe failure
    expect($results)->toHaveCount(1)
        ->and($results[0]->symbol)->toBe('AAPL');
});
