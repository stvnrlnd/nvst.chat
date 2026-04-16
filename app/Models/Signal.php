<?php

namespace App\Models;

use App\Enums\SignalAction;
use Database\Factories\SignalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['symbol', 'action', 'price_at_signal', 'reason', 'confidence', 'executed'])]
class Signal extends Model
{
    /** @use HasFactory<SignalFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'action' => SignalAction::class,
            'price_at_signal' => 'decimal:8',
            'confidence' => 'decimal:2',
            'executed' => 'boolean',
        ];
    }

    public function trade(): HasOne
    {
        return $this->hasOne(Trade::class);
    }

    public function scopeUnexecuted($query): void
    {
        $query->where('executed', false)
            ->where('action', '!=', SignalAction::Hold->value);
    }

    /**
     * Whether a non-Hold signal was already generated for this symbol within the cooldown window.
     */
    public static function isOnCooldown(string $symbol): bool
    {
        $minutes = (int) config('alpaca.signal_cooldown_minutes', 30);

        if ($minutes <= 0) {
            return false;
        }

        return static::query()
            ->where('symbol', $symbol)
            ->where('action', '!=', SignalAction::Hold->value)
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->exists();
    }
}
