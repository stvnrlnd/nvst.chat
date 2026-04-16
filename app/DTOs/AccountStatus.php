<?php

namespace App\DTOs;

readonly class AccountStatus
{
    public function __construct(
        public float $buyingPower,
        public float $portfolioValue,
        public float $equity,
        public float $lastEquity,
        public int $daytradeCount,
        public bool $patternDayTrader,
        public float $daytradingBuyingPower,
    ) {}

    public static function fromAlpaca(array $account): self
    {
        return new self(
            buyingPower: (float) ($account['buying_power'] ?? 0),
            portfolioValue: (float) ($account['portfolio_value'] ?? 0),
            equity: (float) ($account['equity'] ?? 0),
            lastEquity: (float) ($account['last_equity'] ?? 0),
            daytradeCount: (int) ($account['daytrade_count'] ?? 0),
            patternDayTrader: (bool) ($account['pattern_day_trader'] ?? false),
            daytradingBuyingPower: (float) ($account['daytrading_buying_power'] ?? 0),
        );
    }

    public function dayPl(): ?float
    {
        if ($this->lastEquity <= 0) {
            return null;
        }

        return $this->equity - $this->lastEquity;
    }

    /**
     * Remaining day trades allowed in the rolling 5-business-day window.
     * PDT rule triggers on the 4th day trade, so the limit is 3.
     */
    public function remainingDayTrades(): int
    {
        return max(0, 3 - $this->daytradeCount);
    }

    /**
     * Whether this account is approaching or at the PDT limit.
     */
    public function isNearPdtLimit(): bool
    {
        return $this->daytradeCount >= 2;
    }

    /**
     * Whether trading should be blocked due to PDT flag with insufficient equity.
     */
    public function isPdtRestricted(): bool
    {
        return $this->patternDayTrader && $this->equity < 25_000;
    }
}
