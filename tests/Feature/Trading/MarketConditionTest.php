<?php

use App\Models\MarketCondition;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reports bearish when today is marked bearish', function () {
    MarketCondition::create([
        'symbol' => 'SPY',
        'date' => today()->toDateString(),
        'open' => 500.00,
        'close' => 492.00,
        'change_pct' => -1.60,
        'is_bearish' => true,
    ]);

    expect(MarketCondition::isTodayBearish())->toBeTrue();
});

it('reports not bearish when today is marked bullish', function () {
    MarketCondition::create([
        'symbol' => 'SPY',
        'date' => today()->toDateString(),
        'open' => 500.00,
        'close' => 505.00,
        'change_pct' => 1.00,
        'is_bearish' => false,
    ]);

    expect(MarketCondition::isTodayBearish())->toBeFalse();
});

it('defaults to false (allow trading) when no data exists for today', function () {
    expect(MarketCondition::isTodayBearish())->toBeFalse();
});

it('defaults to false when only stale data exists from a prior day', function () {
    MarketCondition::create([
        'symbol' => 'SPY',
        'date' => today()->subDay()->toDateString(),
        'open' => 500.00,
        'close' => 492.00,
        'change_pct' => -1.60,
        'is_bearish' => true,
    ]);

    expect(MarketCondition::isTodayBearish())->toBeFalse();
});

it('can check a non-default symbol', function () {
    MarketCondition::create([
        'symbol' => 'QQQ',
        'date' => today()->toDateString(),
        'open' => 400.00,
        'close' => 394.00,
        'change_pct' => -1.50,
        'is_bearish' => true,
    ]);

    expect(MarketCondition::isTodayBearish('QQQ'))->toBeTrue();
    expect(MarketCondition::isTodayBearish('SPY'))->toBeFalse();
});
