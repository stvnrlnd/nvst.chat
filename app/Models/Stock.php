<?php

namespace App\Models;

use Database\Factories\StockFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['symbol', 'name', 'is_active', 'notes', 'source', 'last_seen_at'])]
class Stock extends Model
{
    /** @use HasFactory<StockFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    public function signals(): HasMany
    {
        return $this->hasMany(Signal::class, 'symbol', 'symbol');
    }

    public function trades(): HasMany
    {
        return $this->hasMany(Trade::class, 'symbol', 'symbol');
    }

    public function scopeActive($query): void
    {
        $query->where('is_active', true);
    }

    public function scopeManual($query): void
    {
        $query->where('source', 'manual');
    }

    public function scopeAuto($query): void
    {
        $query->where('source', 'auto');
    }

    /**
     * Auto symbols not seen within the given number of days (candidates for deactivation).
     */
    public function scopeStale($query, int $days = 7): void
    {
        $query->where('source', 'auto')
            ->where(function ($q) use ($days) {
                $q->whereNull('last_seen_at')
                    ->orWhere('last_seen_at', '<', now()->subDays($days));
            });
    }

    public function isManual(): bool
    {
        return $this->source === 'manual';
    }

    public function isAuto(): bool
    {
        return $this->source === 'auto';
    }
}
