<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'symbol',
    'screened_date',
    'score',
    'price',
    'atr',
    'atr_pct',
    'sma5',
    'sma20',
    'up_days',
    'disqualified',
    'disqualified_reason',
])]
class ScreenerCandidate extends Model
{
    protected function casts(): array
    {
        return [
            'screened_date' => 'date',
            'score' => 'float',
            'price' => 'float',
            'atr' => 'float',
            'atr_pct' => 'float',
            'sma5' => 'float',
            'sma20' => 'float',
            'up_days' => 'integer',
            'disqualified' => 'boolean',
        ];
    }

    /** Candidates for a specific date, best score first. */
    public function scopeForDate(Builder $query, string $date): void
    {
        $query->where('screened_date', $date)
            ->where('disqualified', false)
            ->orderByDesc('score');
    }
}
