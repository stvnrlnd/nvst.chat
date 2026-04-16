<?php

use App\Enums\SignalAction;
use App\Models\NewsArticle;
use App\Models\Signal;
use App\Services\AlpacaService;
use App\Services\MarketDataService;
use App\Services\SignalService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Helpers to build bar arrays and price arrays for test scenarios.

/**
 * @param  float[]  $closes
 * @return array<int, array<string, float>>
 */
function barsFromCloses(array $closes): array
{
    return array_map(fn ($c) => ['c' => $c, 'o' => $c, 'h' => $c, 'l' => $c, 'v' => 1000], $closes);
}

/**
 * Build a price array that produces a BUY crossover on the last bar.
 *
 * 20 bars at $10, then 1 bar at $10, then 1 spike to $20.
 * SMA5_prev = avg(10,10,10,10,10) = 10  ==  SMA20_prev = 10  → no prior crossover
 * SMA5_now  = avg(10,10,10,10,20) = 12  >   SMA20_now ≈ 10.5 → crossedAbove
 */
function buyCrossoverBars(): array
{
    return barsFromCloses([...array_fill(0, 21, 10.0), 20.0]);
}

/**
 * Build a price array that produces a SELL crossover on the last bar.
 *
 * 20 bars at $20, then 1 bar at $20, then 1 drop to $10.
 * SMA5_prev = avg(20,20,20,20,20) = 20  ==  SMA20_prev = 20  → no prior crossover
 * SMA5_now  = avg(20,20,20,20,10) = 18  <   SMA20_now ≈ 19.5 → crossedBelow
 */
function sellCrossoverBars(): array
{
    return barsFromCloses([...array_fill(0, 21, 20.0), 10.0]);
}

/**
 * 22 flat bars — SMAs are always equal, no crossover ever fires.
 */
function flatBars(): array
{
    return barsFromCloses(array_fill(0, 22, 15.0));
}

beforeEach(function () {
    $this->alpacaMock = $this->mock(AlpacaService::class);
    $marketData = new MarketDataService($this->alpacaMock);
    $this->service = new SignalService($marketData);
});

it('generates a Buy signal on an upward SMA crossover', function () {
    $this->alpacaMock->allows('getBars')->andReturn(buyCrossoverBars());
    $this->alpacaMock->allows('getLatestTrade')->andReturn(['p' => 20.0]);

    $signal = $this->service->generateSignal('AAPL');

    expect($signal->action)->toBe(SignalAction::Buy)
        ->and($signal->confidence)->toBe('0.70')
        ->and($signal->reason)->toContain('crossed above');
});

it('generates a Sell signal on a downward SMA crossover', function () {
    $this->alpacaMock->allows('getBars')->andReturn(sellCrossoverBars());
    $this->alpacaMock->allows('getLatestTrade')->andReturn(['p' => 10.0]);

    $signal = $this->service->generateSignal('AAPL');

    expect($signal->action)->toBe(SignalAction::Sell)
        ->and($signal->reason)->toContain('crossed below');
});

it('generates a Hold signal when there is no crossover', function () {
    $this->alpacaMock->allows('getBars')->andReturn(flatBars());

    $signal = $this->service->generateSignal('AAPL');

    expect($signal->action)->toBe(SignalAction::Hold)
        ->and($signal->reason)->toContain('No crossover');
});

it('generates a Hold signal when there is insufficient price history', function () {
    $this->alpacaMock->allows('getBars')->andReturn(barsFromCloses([10.0, 11.0]));

    $signal = $this->service->generateSignal('AAPL');

    expect($signal->action)->toBe(SignalAction::Hold)
        ->and($signal->reason)->toContain('Insufficient');
});

it('generates a Hold signal when the data API returns empty', function () {
    $this->alpacaMock->allows('getBars')->andReturn([]);

    $signal = $this->service->generateSignal('AAPL');

    expect($signal->action)->toBe(SignalAction::Hold);
});

it('downgrades a Buy to Hold when negative sentiment is below the threshold', function () {
    config(['alpaca.sentiment_threshold' => -0.3]);

    $this->alpacaMock->allows('getBars')->andReturn(buyCrossoverBars());
    $this->alpacaMock->allows('getLatestTrade')->andReturn(['p' => 20.0]);

    // Three negative articles → aggregate sentiment = -1.0, well below -0.3
    NewsArticle::factory()->count(3)->create(['symbol' => 'AAPL', 'sentiment' => 'negative']);

    $signal = $this->service->generateSignal('AAPL');

    expect($signal->action)->toBe(SignalAction::Hold)
        ->and($signal->reason)->toContain('Sentiment filter');
});

it('does not suppress a Buy when sentiment is neutral', function () {
    config(['alpaca.sentiment_threshold' => -0.3]);

    $this->alpacaMock->allows('getBars')->andReturn(buyCrossoverBars());
    $this->alpacaMock->allows('getLatestTrade')->andReturn(['p' => 20.0]);

    NewsArticle::factory()->count(3)->create(['symbol' => 'AAPL', 'sentiment' => 'neutral']);

    $signal = $this->service->generateSignal('AAPL');

    expect($signal->action)->toBe(SignalAction::Buy);
});

it('returns a cooldown Hold without calling Alpaca when cooldown is active', function () {
    Signal::factory()->buy()->create(['symbol' => 'AAPL', 'created_at' => now()->subMinutes(5)]);

    $this->alpacaMock->shouldNotReceive('getBars');

    $signal = $this->service->generateSignal('AAPL');

    expect($signal->action)->toBe(SignalAction::Hold)
        ->and($signal->reason)->toContain('Cooldown');
});

it('generates a new signal after the cooldown window has passed', function () {
    Signal::factory()->buy()->create([
        'symbol' => 'AAPL',
        'created_at' => now()->subMinutes(31),
    ]);

    $this->alpacaMock->allows('getBars')->andReturn(buyCrossoverBars());
    $this->alpacaMock->allows('getLatestTrade')->andReturn(['p' => 20.0]);

    $signal = $this->service->generateSignal('AAPL');

    expect($signal->action)->toBe(SignalAction::Buy);
});
