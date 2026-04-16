<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

#[Fillable(['symbol', 'report_date', 'refreshed_at'])]
class EarningsEvent extends Model
{
    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'refreshed_at' => 'datetime',
        ];
    }

    /**
     * Check whether any earnings event for the symbol falls within the blackout window.
     *
     * @param  int  $daysBefore  Days before earnings to start blocking
     * @param  int  $daysAfter  Days after earnings to keep blocking
     */
    public static function isInBlackout(string $symbol, int $daysBefore = 2, int $daysAfter = 1): bool
    {
        $today = Carbon::today();

        return static::query()
            ->where('symbol', $symbol)
            ->whereDate('report_date', '>=', $today->copy()->subDays($daysAfter))
            ->whereDate('report_date', '<=', $today->copy()->addDays($daysBefore))
            ->exists();
    }

    /**
     * Return the nearest upcoming earnings date for a symbol, or null if none known.
     */
    public static function nextEarningsDate(string $symbol): ?CarbonInterface
    {
        $row = static::query()
            ->where('symbol', $symbol)
            ->whereDate('report_date', '>=', Carbon::today())
            ->orderBy('report_date')
            ->first();

        return $row?->report_date;
    }
}
