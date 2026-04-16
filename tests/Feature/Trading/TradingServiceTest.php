<?php

use App\DTOs\AccountStatus;
use App\Enums\SignalAction;
use App\Jobs\ExecuteTradeJob;
use App\Models\EarningsEvent;
use App\Models\MarketCondition;
use App\Models\Position;
use App\Models\Signal;
use App\Services\AlpacaService;
use App\Services\TradingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function makeAccount(array $overrides = []): AccountStatus
{
    return new AccountStatus(
        buyingPower: $overrides['buyingPower'] ?? 500.0,
        portfolioValue: $overrides['portfolioValue'] ?? 1000.0,
        equity: $overrides['equity'] ?? 1000.0,
        lastEquity: $overrides['lastEquity'] ?? 980.0,
        daytradeCount: $overrides['daytradeCount'] ?? 0,
        patternDayTrader: $overrides['patternDayTrader'] ?? false,
        daytradingBuyingPower: $overrides['daytradingBuyingPower'] ?? 0.0,
    );
}

beforeEach(function () {
    $this->alpacaMock = $this->mock(AlpacaService::class);
    $this->service = new TradingService($this->alpacaMock);

    Queue::fake();
    config(['alpaca.trading_enabled' => true]);
});

// ──────────────────────────────────────────────────────────────────────────────
// executeSignal
// ──────────────────────────────────────────────────────────────────────────────

it('returns null and logs when trading is disabled', function () {
    config(['alpaca.trading_enabled' => false]);

    $signal = Signal::factory()->buy()->create(['symbol' => 'AAPL', 'price_at_signal' => 100.0]);

    $trade = $this->service->executeSignal($signal);

    expect($trade)->toBeNull();
});

it('returns null for a Hold signal', function () {
    $signal = Signal::factory()->hold()->create(['symbol' => 'AAPL', 'price_at_signal' => 100.0]);

    $trade = $this->service->executeSignal($signal);

    expect($trade)->toBeNull();
});

it('returns null when portfolio value is zero', function () {
    $this->alpacaMock->allows('getAccountStatus')->andReturn(makeAccount(['portfolioValue' => 0]));

    $signal = Signal::factory()->buy()->create(['symbol' => 'AAPL', 'price_at_signal' => 100.0]);

    $trade = $this->service->executeSignal($signal);

    expect($trade)->toBeNull();
});

it('returns null when PDT restriction is active', function () {
    $this->alpacaMock->allows('getAccountStatus')->andReturn(
        makeAccount(['patternDayTrader' => true, 'equity' => 10_000.0])
    );

    $signal = Signal::factory()->buy()->create(['symbol' => 'AAPL', 'price_at_signal' => 100.0]);

    $trade = $this->service->executeSignal($signal);

    expect($trade)->toBeNull();
    expect(Signal::find($signal->id)->executed)->toBeTrue();
});

it('returns null during an earnings blackout', function () {
    EarningsEvent::create(['symbol' => 'AAPL', 'report_date' => today()->toDateString()]);

    $this->alpacaMock->allows('getAccountStatus')->andReturn(makeAccount());

    $signal = Signal::factory()->buy()->create(['symbol' => 'AAPL', 'price_at_signal' => 100.0]);

    $trade = $this->service->executeSignal($signal);

    expect($trade)->toBeNull();
    expect(Signal::find($signal->id)->executed)->toBeTrue();
});

it('suppresses a Buy signal on a bearish market day', function () {
    MarketCondition::create([
        'symbol' => 'SPY',
        'date' => today()->toDateString(),
        'open' => 500.0,
        'close' => 492.0,
        'change_pct' => -1.60,
        'is_bearish' => true,
    ]);

    $this->alpacaMock->allows('getAccountStatus')->andReturn(makeAccount());

    $signal = Signal::factory()->buy()->create(['symbol' => 'AAPL', 'price_at_signal' => 100.0]);

    $trade = $this->service->executeSignal($signal);

    expect($trade)->toBeNull();
    expect(Signal::find($signal->id)->executed)->toBeTrue();
});

it('sizes a buy as position_size_pct of portfolio', function () {
    config(['alpaca.position_size_pct' => 0.05, 'alpaca.max_position_pct' => 0.20]);

    $this->alpacaMock->allows('getAccountStatus')->andReturn(makeAccount([
        'portfolioValue' => 1000.0,
        'buyingPower' => 1000.0,
    ]));
    $this->alpacaMock->allows('submitOrder')->andReturn(['id' => 'order-1']);

    $signal = Signal::factory()->buy()->create(['symbol' => 'AAPL', 'price_at_signal' => 100.0]);

    $trade = $this->service->executeSignal($signal);

    // 5% of $1,000 = $50 / $100 per share = 0.5 shares
    expect($trade)->not->toBeNull()
        ->and((float) $trade->qty)->toBe(0.5);
});

it('skips a buy when the max position size is already reached', function () {
    config(['alpaca.position_size_pct' => 0.05, 'alpaca.max_position_pct' => 0.20]);

    $this->alpacaMock->allows('getAccountStatus')->andReturn(makeAccount(['portfolioValue' => 1000.0]));

    // Existing position already at 20% of $1,000 = $200
    Position::factory()->create(['symbol' => 'AAPL', 'market_value' => 200.0]);

    $signal = Signal::factory()->buy()->create(['symbol' => 'AAPL', 'price_at_signal' => 100.0]);

    $trade = $this->service->executeSignal($signal);

    expect($trade)->toBeNull();
    expect(Signal::find($signal->id)->executed)->toBeTrue();
});

it('returns null when there is no position to sell', function () {
    $this->alpacaMock->allows('getAccountStatus')->andReturn(makeAccount());

    $signal = Signal::factory()->sell()->create(['symbol' => 'AAPL', 'price_at_signal' => 100.0]);

    $trade = $this->service->executeSignal($signal);

    expect($trade)->toBeNull();
    expect(Signal::find($signal->id)->executed)->toBeTrue();
});

it('submits a sell for the full position qty', function () {
    $this->alpacaMock->allows('getAccountStatus')->andReturn(makeAccount());
    $this->alpacaMock->allows('submitOrder')->andReturn(['id' => 'order-sell-1']);

    Position::factory()->create(['symbol' => 'AAPL', 'qty' => 2.5]);

    $signal = Signal::factory()->sell()->create(['symbol' => 'AAPL', 'price_at_signal' => 100.0]);

    $trade = $this->service->executeSignal($signal);

    expect($trade)->not->toBeNull()
        ->and((float) $trade->qty)->toBe(2.5);
});

it('marks the signal as executed after a successful trade', function () {
    $this->alpacaMock->allows('getAccountStatus')->andReturn(makeAccount(['portfolioValue' => 1000.0, 'buyingPower' => 1000.0]));
    $this->alpacaMock->allows('submitOrder')->andReturn(['id' => 'order-2']);

    $signal = Signal::factory()->buy()->create(['symbol' => 'AAPL', 'price_at_signal' => 100.0]);

    $this->service->executeSignal($signal);

    expect(Signal::find($signal->id)->executed)->toBeTrue();
});

// ──────────────────────────────────────────────────────────────────────────────
// checkStopLosses
// ──────────────────────────────────────────────────────────────────────────────

it('dispatches a sell job when a position breaches the stop-loss threshold', function () {
    config(['alpaca.stop_loss_pct' => -0.05]);

    Position::factory()->create(['symbol' => 'AAPL', 'unrealized_plpc' => -0.08, 'current_price' => 92.0]);

    $triggered = $this->service->checkStopLosses();

    expect($triggered)->toBe(1);
    Queue::assertPushed(ExecuteTradeJob::class);

    $signal = Signal::where('symbol', 'AAPL')->where('action', SignalAction::Sell)->first();
    expect($signal)->not->toBeNull()
        ->and($signal->reason)->toContain('Stop-loss');
});

it('does not trigger stop-loss for positions within the threshold', function () {
    config(['alpaca.stop_loss_pct' => -0.05]);

    Position::factory()->create(['symbol' => 'AAPL', 'unrealized_plpc' => -0.02, 'current_price' => 98.0]);

    $triggered = $this->service->checkStopLosses();

    expect($triggered)->toBe(0);
    Queue::assertNotPushed(ExecuteTradeJob::class);
});

it('returns 0 when stop_loss_pct is null (disabled)', function () {
    config(['alpaca.stop_loss_pct' => null]);

    Position::factory()->create(['symbol' => 'AAPL', 'unrealized_plpc' => -0.50, 'current_price' => 50.0]);

    $triggered = $this->service->checkStopLosses();

    expect($triggered)->toBe(0);
});

it('triggers stop-loss for multiple positions in one cycle', function () {
    config(['alpaca.stop_loss_pct' => -0.05]);

    Position::factory()->create(['symbol' => 'AAPL', 'unrealized_plpc' => -0.10, 'current_price' => 90.0]);
    Position::factory()->create(['symbol' => 'MSFT', 'unrealized_plpc' => -0.07, 'current_price' => 93.0]);
    Position::factory()->create(['symbol' => 'GOOG', 'unrealized_plpc' => -0.02, 'current_price' => 98.0]);

    $triggered = $this->service->checkStopLosses();

    expect($triggered)->toBe(2);
    Queue::assertPushed(ExecuteTradeJob::class, 2);
});
