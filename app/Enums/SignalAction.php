<?php

namespace App\Enums;

enum SignalAction: string
{
    case Buy = 'buy';
    case Sell = 'sell';
    case Hold = 'hold';

    public function label(): string
    {
        return match ($this) {
            self::Buy => 'Buy',
            self::Sell => 'Sell',
            self::Hold => 'Hold',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Buy => 'green',
            self::Sell => 'red',
            self::Hold => 'zinc',
        };
    }
}
