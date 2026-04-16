<?php

use App\Models\EarningsEvent;
use App\Models\ScreenerCandidate;
use App\Models\Stock;
use App\Services\AlpacaService;
use App\Services\MarketDataService;
use App\Services\ScreenerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

/** 30 bars trending up from $40 → ~$50, ATR% ≈ 3% (sweet spot). */
function goodBars(): array
{
    return makeBars(30, 40.0, 0.33, 1.5);
}

// ── Setup ─────────────────────────────────────────────────────────────────────

beforeEach(function () {
    $this->alpacaMock = $this->mock(AlpacaService::class);
    $this->marketData = new MarketDataService($this->alpacaMock);
    $this->service = new ScreenerService($this->alpacaMock, $this->marketData);

    config([
        'alpaca.screener_min_price' => 5.0,
        'alpaca.screener_min_atr_pct' => 1.0,
        'alpaca.screener_max_atr_pct' => 8.0,
    ]);
});

// ── Core scoring ──────────────────────────────────────────────────────────────

it('scores a qualified symbol and saves a candidate', function () {
    Stock::factory()->create(['symbol' => 'AAPL']);
    $this->alpacaMock->allows('getBars')->with('AAPL', 30)->andReturn(goodBars());

    $results = $this->service->run(Carbon::today());

    expect($results)->toHaveCount(1);

    $candidate = $results[0];
    expect($candidate->symbol)->toBe('AAPL')
        ->and($candidate->disqualified)->toBeFalse()
        ->and($candidate->score)->toBeGreaterThan(50.0)
        ->and($candidate->price)->toBeGreaterThan(0)
        ->and($candidate->sma5)->toBeGreaterThan(0)
        ->and($candidate->sma20)->toBeGreaterThan(0)
        ->and($candidate->up_days)->toBe(5);

    // Persisted to DB
    expect(ScreenerCandidate::where('symbol', 'AAPL')->count())->toBe(1);
});

it('returns candidates sorted best score first', function () {
    Stock::factory()->create(['symbol' => 'AAPL']);
    Stock::factory()->create(['symbol' => 'MSFT']);

    // AAPL: good uptrending bars (high score)
    $this->alpacaMock->allows('getBars')->with('AAPL', 30)->andReturn(goodBars());

    // MSFT: flat bars with tiny range → will be disqualified (ATR% too low) or score lower
    // Use a downtrending stock so SMA5 < SMA20 → 0 momentum, only trend pts possible
    $this->alpacaMock->allows('getBars')->with('MSFT', 30)->andReturn(
        makeBars(30, 55.0, -0.33, 1.5) // downtrend
    );

    $results = $this->service->run(Carbon::today());

    // AAPL should come first (higher score)
    expect($results[0]->symbol)->toBe('AAPL');
});

// ── Disqualifiers ─────────────────────────────────────────────────────────────

it('disqualifies a symbol in an earnings blackout window', function () {
    Stock::factory()->create(['symbol' => 'AAPL']);
    EarningsEvent::create(['symbol' => 'AAPL', 'report_date' => today()->toDateString()]);
    $this->alpacaMock->allows('getBars')->with('AAPL', 30)->andReturn(goodBars());

    $results = $this->service->run(Carbon::today());

    expect($results)->toHaveCount(1);
    expect($results[0]->disqualified)->toBeTrue()
        ->and($results[0]->disqualified_reason)->toContain('Earnings');
});

it('disqualifies a symbol whose price is below the minimum', function () {
    Stock::factory()->create(['symbol' => 'AAPL']);

    // Bars that end around $3.30 — below the $5 minimum
    $this->alpacaMock->allows('getBars')->with('AAPL', 30)->andReturn(
        makeBars(30, 2.0, 0.044, 0.15)
    );

    $results = $this->service->run(Carbon::today());

    expect($results[0]->disqualified)->toBeTrue()
        ->and($results[0]->disqualified_reason)->toContain('minimum');
});

it('disqualifies a symbol whose ATR% is below the minimum', function () {
    Stock::factory()->create(['symbol' => 'AAPL']);

    // $50 stock with only $0.02 daily range → ATR% ≈ 0.04% (way below 1% min)
    $this->alpacaMock->allows('getBars')->with('AAPL', 30)->andReturn(
        makeBars(30, 49.0, 0.033, 0.02)
    );

    $results = $this->service->run(Carbon::today());

    expect($results[0]->disqualified)->toBeTrue()
        ->and($results[0]->disqualified_reason)->toContain('too low');
});

it('disqualifies a symbol whose ATR% is above the maximum', function () {
    Stock::factory()->create(['symbol' => 'AAPL']);

    // $50 stock with $10 daily range → ATR% ≈ 20% (above 8% max)
    $this->alpacaMock->allows('getBars')->with('AAPL', 30)->andReturn(
        makeBars(30, 40.0, 0.33, 10.0)
    );

    $results = $this->service->run(Carbon::today());

    expect($results[0]->disqualified)->toBeTrue()
        ->and($results[0]->disqualified_reason)->toContain('too high');
});

it('disqualifies a symbol with insufficient price history', function () {
    Stock::factory()->create(['symbol' => 'AAPL']);

    // Only 10 bars — below the 22-bar minimum
    $this->alpacaMock->allows('getBars')->with('AAPL', 30)->andReturn(
        makeBars(10, 40.0, 0.33, 1.5)
    );

    $results = $this->service->run(Carbon::today());

    expect($results[0]->disqualified)->toBeTrue()
        ->and($results[0]->disqualified_reason)->toContain('Insufficient');
});

// ── Resilience ────────────────────────────────────────────────────────────────

it('skips a symbol gracefully when the API throws', function () {
    Stock::factory()->create(['symbol' => 'AAPL']);
    Stock::factory()->create(['symbol' => 'MSFT']);

    $this->alpacaMock->allows('getBars')->with('AAPL', 30)->andThrow(new RuntimeException('timeout'));
    $this->alpacaMock->allows('getBars')->with('MSFT', 30)->andReturn(goodBars());

    $results = $this->service->run(Carbon::today());

    // AAPL skipped entirely, MSFT still scored
    expect($results)->toHaveCount(1)
        ->and($results[0]->symbol)->toBe('MSFT');

    expect(ScreenerCandidate::where('symbol', 'AAPL')->count())->toBe(0);
});

it('returns an empty array when no active symbols exist', function () {
    Stock::factory()->inactive()->create(['symbol' => 'AAPL']);

    $results = $this->service->run(Carbon::today());

    expect($results)->toBeEmpty();
    expect(ScreenerCandidate::count())->toBe(0);
});

// ── Idempotency ───────────────────────────────────────────────────────────────

it('clears and replaces candidates when re-run on the same date', function () {
    Stock::factory()->create(['symbol' => 'AAPL']);
    $this->alpacaMock->allows('getBars')->with('AAPL', 30)->andReturn(goodBars());

    $this->service->run(Carbon::today());
    $this->service->run(Carbon::today());

    // Still only one row per symbol per date
    expect(ScreenerCandidate::where('symbol', 'AAPL')->count())->toBe(1);
});
