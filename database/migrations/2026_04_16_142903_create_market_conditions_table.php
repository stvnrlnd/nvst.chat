<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('market_conditions', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 10)->default('SPY'); // Index/ETF used as proxy
            $table->date('date')->unique();
            $table->decimal('open', 18, 8);
            $table->decimal('close', 18, 8);
            $table->decimal('change_pct', 8, 4);          // (close - open) / open * 100
            $table->boolean('is_bearish');                 // true when change_pct < threshold
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('market_conditions');
    }
};
