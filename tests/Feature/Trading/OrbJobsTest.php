<?php

use App\Enums\SignalAction;
use App\Jobs\OrbEntryJob;
use App\Jobs\OrbExitJob;
use App\Models\DailyPlay;
use App\Models\MarketCondition;
use App\Models\Position;
use App\Models\ScreenerCandidate;
use App\Models\Signal;
use App\Services\AlpacaService;
use App\Services\MarketDataService;
use App\Services\TradingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function makeCandidate(string $symbol = 'AAPL', float $score = 80.0): ScreenerCandidate
{
    return ScreenerCandidate::create([
        'symbol' => $symbol,
        'screened_date' => today()->toDateString(),
        'score' => $score,
        'price' => 150.0,
        'atr' => 3.0,
        'atr_pct' => 2.0,
        'sma5' => 155.0,
        'sma20' => 148.0,
        'up_days' => 4,
        'disqualified' => false,
    ]);
}

// ── OrbEntryJob ───────────────────────────────────────────────────────────────

describe('OrbEntryJob', function () {
    beforeEach(function () {
        $this->alpacaMock = $this->mock(AlpacaService::class);
        $this->marketDataMock = $this->mock(MarketDataService::class);
        $this->tradingMock = $this->mock(TradingService::class);

        config(['alpaca.trading_enabled' => true]);
    });

    it('enters a position for the top screener candidate', function () {
        makeCandidate('AAPL', 80.0);
        $this->alpacaMock->allows('isMarketOpen')->andReturn(true);
        $this->marketDataMock->allows('getCurrentPrice')->andReturn(150.0);
        $this->tradingMock->allows('executeSignal')->andReturnUsing(
            fn (Signal $signal) => new \App\Models\Trade(['symbol' => 'AAPL', 'alpaca_order_id' => 'order-orb-1'])
        );

        (new OrbEntryJob)->handle($this->alpacaMock, $this->marketDataMock, $this->tradingMock);

        $play = DailyPlay::today()->first();
        expect($play)->not->toBeNull()
            ->and($play->status)->toBe('entered')
            ->and($play->symbol)->toBe('AAPL')
            ->and($play->entered_at)->not->toBeNull()
            ->and($play->entry_signal_id)->not->toBeNull();

        $signal = Signal::find($play->entry_signal_id);
        expect($signal->action)->toBe(SignalAction::Buy)
            ->and($signal->reason)->toContain('[ORB]');
    });

    it('is idempotent — skips if a daily play already exists for today', function () {
        DailyPlay::create(['symbol' => 'AAPL', 'date' => today(), 'status' => 'entered', 'entered_at' => now()]);

        $this->alpacaMock->shouldNotReceive('isMarketOpen');
        $this->tradingMock->shouldNotReceive('executeSignal');

        (new OrbEntryJob)->handle($this->alpacaMock, $this->marketDataMock, $this->tradingMock);

        expect(DailyPlay::count())->toBe(1);
    });

    it('creates a skipped play when trading is disabled', function () {
        config(['alpaca.trading_enabled' => false]);

        (new OrbEntryJob)->handle($this->alpacaMock, $this->marketDataMock, $this->tradingMock);

        $play = DailyPlay::today()->first();
        expect($play->status)->toBe('skipped')
            ->and($play->skip_reason)->toContain('disabled');
    });

    it('creates a skipped play when market is not open', function () {
        $this->alpacaMock->allows('isMarketOpen')->andReturn(false);

        (new OrbEntryJob)->handle($this->alpacaMock, $this->marketDataMock, $this->tradingMock);

        $play = DailyPlay::today()->first();
        expect($play->status)->toBe('skipped')
            ->and($play->skip_reason)->toContain('Market is not open');
    });

    it('creates a skipped play when no screener candidates exist', function () {
        $this->alpacaMock->allows('isMarketOpen')->andReturn(true);

        (new OrbEntryJob)->handle($this->alpacaMock, $this->marketDataMock, $this->tradingMock);

        $play = DailyPlay::today()->first();
        expect($play->status)->toBe('skipped')
            ->and($play->skip_reason)->toContain('No qualified screener candidates');
    });

    it('creates a skipped play when the macro is bearish', function () {
        makeCandidate('AAPL');
        $this->alpacaMock->allows('isMarketOpen')->andReturn(true);
        MarketCondition::create([
            'symbol' => 'SPY',
            'date' => today()->toDateString(),
            'open' => 500.0,
            'close' => 490.0,
            'change_pct' => -2.0,
            'is_bearish' => true,
        ]);

        (new OrbEntryJob)->handle($this->alpacaMock, $this->marketDataMock, $this->tradingMock);

        $play = DailyPlay::today()->first();
        expect($play->status)->toBe('skipped')
            ->and($play->skip_reason)->toContain('bearish');
    });

    it('creates a skipped play when the current price cannot be fetched', function () {
        makeCandidate('AAPL');
        $this->alpacaMock->allows('isMarketOpen')->andReturn(true);
        $this->marketDataMock->allows('getCurrentPrice')->andReturn(null);

        (new OrbEntryJob)->handle($this->alpacaMock, $this->marketDataMock, $this->tradingMock);

        $play = DailyPlay::today()->first();
        expect($play->status)->toBe('skipped')
            ->and($play->skip_reason)->toContain('current price');
    });

    it('creates a skipped play when executeSignal returns null (blocked by PDT etc)', function () {
        makeCandidate('AAPL');
        $this->alpacaMock->allows('isMarketOpen')->andReturn(true);
        $this->marketDataMock->allows('getCurrentPrice')->andReturn(150.0);
        $this->tradingMock->allows('executeSignal')->andReturn(null);

        (new OrbEntryJob)->handle($this->alpacaMock, $this->marketDataMock, $this->tradingMock);

        $play = DailyPlay::today()->first();
        expect($play->status)->toBe('skipped')
            ->and($play->skip_reason)->toContain('execution checks')
            ->and($play->entry_signal_id)->not->toBeNull();
    });

    it('picks the highest-scored candidate when multiple exist', function () {
        makeCandidate('MSFT', 55.0);
        makeCandidate('AAPL', 90.0);
        $this->alpacaMock->allows('isMarketOpen')->andReturn(true);
        $this->marketDataMock->allows('getCurrentPrice')->andReturn(150.0);

        $captured = null;
        $this->tradingMock->allows('executeSignal')->andReturnUsing(function (Signal $signal) use (&$captured) {
            $captured = $signal->symbol;

            return new \App\Models\Trade(['symbol' => $signal->symbol, 'alpaca_order_id' => 'order-1']);
        });

        (new OrbEntryJob)->handle($this->alpacaMock, $this->marketDataMock, $this->tradingMock);

        expect($captured)->toBe('AAPL');
    });
});

// ── OrbExitJob ────────────────────────────────────────────────────────────────

describe('OrbExitJob', function () {
    beforeEach(function () {
        $this->marketDataMock = $this->mock(MarketDataService::class);
        $this->tradingMock = $this->mock(TradingService::class);

        $this->marketDataMock->allows('getCurrentPrice')->andReturn(155.0);
    });

    it('exits an entered play by submitting a sell signal', function () {
        $play = DailyPlay::create([
            'symbol' => 'AAPL',
            'date' => today(),
            'status' => 'entered',
            'entered_at' => now()->subMinutes(20),
        ]);

        Position::factory()->create(['symbol' => 'AAPL', 'qty' => 1.5]);

        $this->tradingMock->allows('executeSignal')->andReturnUsing(function (Signal $signal) {
            return new \App\Models\Trade(['symbol' => 'AAPL', 'alpaca_order_id' => 'order-exit-1']);
        });

        (new OrbExitJob)->handle($this->marketDataMock, $this->tradingMock);

        $play->refresh();
        expect($play->status)->toBe('exited')
            ->and($play->exited_at)->not->toBeNull()
            ->and($play->exit_signal_id)->not->toBeNull();

        $signal = Signal::find($play->exit_signal_id);
        expect($signal->action)->toBe(SignalAction::Sell)
            ->and($signal->reason)->toContain('[ORB]');
    });

    it('does nothing when no entered play exists for today', function () {
        // Play is skipped, not entered
        DailyPlay::create(['symbol' => 'N/A', 'date' => today(), 'status' => 'skipped', 'skip_reason' => 'test']);

        $this->tradingMock->shouldNotReceive('executeSignal');

        (new OrbExitJob)->handle($this->marketDataMock, $this->tradingMock);

        expect(Signal::count())->toBe(0);
    });

    it('marks play exited when position was already closed before the exit window', function () {
        $play = DailyPlay::create([
            'symbol' => 'AAPL',
            'date' => today(),
            'status' => 'entered',
            'entered_at' => now()->subMinutes(20),
        ]);

        // No Position row — stop-loss or manual close already removed it

        $this->tradingMock->shouldNotReceive('executeSignal');

        (new OrbExitJob)->handle($this->marketDataMock, $this->tradingMock);

        $play->refresh();
        expect($play->status)->toBe('exited')
            ->and($play->skip_reason)->toContain('already closed');
    });

    it('still marks play exited even when executeSignal returns null', function () {
        DailyPlay::create([
            'symbol' => 'AAPL',
            'date' => today(),
            'status' => 'entered',
            'entered_at' => now()->subMinutes(20),
        ]);

        Position::factory()->create(['symbol' => 'AAPL', 'qty' => 1.5]);

        $this->tradingMock->allows('executeSignal')->andReturn(null);

        (new OrbExitJob)->handle($this->marketDataMock, $this->tradingMock);

        $play = DailyPlay::today()->first();
        expect($play->status)->toBe('exited');
    });
});
