<?php

namespace App\Enums;

enum TradeStatus: string
{
    case Pending = 'pending';
    case Filled = 'filled';
    case PartiallyFilled = 'partially_filled';
    case Canceled = 'canceled';
    case Rejected = 'rejected';
    case Expired = 'expired';

    public function color(): string
    {
        return match ($this) {
            self::Filled => 'green',
            self::PartiallyFilled => 'yellow',
            self::Pending => 'blue',
            self::Canceled, self::Expired => 'zinc',
            self::Rejected => 'red',
        };
    }
}
