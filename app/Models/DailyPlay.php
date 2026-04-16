<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'symbol',
    'date',
    'status',
    'skip_reason',
    'entry_signal_id',
    'exit_signal_id',
    'entered_at',
    'exited_at',
])]
class DailyPlay extends Model
{
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'entered_at' => 'datetime',
            'exited_at' => 'datetime',
        ];
    }

    public function entrySignal(): BelongsTo
    {
        return $this->belongsTo(Signal::class, 'entry_signal_id');
    }

    public function exitSignal(): BelongsTo
    {
        return $this->belongsTo(Signal::class, 'exit_signal_id');
    }

    /** Today's play, if any. */
    public function scopeToday(Builder $query): void
    {
        $query->whereDate('date', today());
    }

    /** Plays that were successfully entered and are awaiting exit. */
    public function scopeEntered(Builder $query): void
    {
        $query->where('status', 'entered');
    }

    public function isEntered(): bool
    {
        return $this->status === 'entered';
    }

    public function isExited(): bool
    {
        return $this->status === 'exited';
    }

    public function isSkipped(): bool
    {
        return $this->status === 'skipped';
    }
}
