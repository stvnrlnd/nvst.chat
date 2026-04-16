<?php

use App\Models\EarningsEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('detects a symbol inside the blackout window', function () {
    EarningsEvent::create(['symbol' => 'AAPL', 'report_date' => now()->toDateString()]);

    expect(EarningsEvent::isInBlackout('AAPL'))->toBeTrue();
});

it('detects a symbol when earnings are 2 days away', function () {
    EarningsEvent::create(['symbol' => 'AAPL', 'report_date' => now()->addDays(2)->toDateString()]);

    expect(EarningsEvent::isInBlackout('AAPL'))->toBeTrue();
});

it('detects a symbol 1 day after earnings', function () {
    EarningsEvent::create(['symbol' => 'AAPL', 'report_date' => now()->subDay()->toDateString()]);

    expect(EarningsEvent::isInBlackout('AAPL'))->toBeTrue();
});

it('is not in blackout when earnings are far in the future', function () {
    EarningsEvent::create(['symbol' => 'AAPL', 'report_date' => now()->addDays(10)->toDateString()]);

    expect(EarningsEvent::isInBlackout('AAPL'))->toBeFalse();
});

it('is not in blackout when no earnings data exists', function () {
    expect(EarningsEvent::isInBlackout('AAPL'))->toBeFalse();
});

it('is not in blackout for a different symbol', function () {
    EarningsEvent::create(['symbol' => 'MSFT', 'report_date' => now()->toDateString()]);

    expect(EarningsEvent::isInBlackout('AAPL'))->toBeFalse();
});

it('returns the next upcoming earnings date', function () {
    $future = now()->addDays(5)->toDateString();
    EarningsEvent::create(['symbol' => 'AAPL', 'report_date' => $future]);

    expect(EarningsEvent::nextEarningsDate('AAPL')->toDateString())->toBe($future);
});

it('returns null from nextEarningsDate when no upcoming dates exist', function () {
    EarningsEvent::create(['symbol' => 'AAPL', 'report_date' => now()->subDays(10)->toDateString()]);

    expect(EarningsEvent::nextEarningsDate('AAPL'))->toBeNull();
});
