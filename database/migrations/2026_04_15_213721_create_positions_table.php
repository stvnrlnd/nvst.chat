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
        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 10)->unique();
            $table->decimal('qty', 18, 8)->default(0);
            $table->decimal('avg_entry_price', 18, 8)->default(0);
            $table->decimal('current_price', 18, 8)->nullable();
            $table->decimal('market_value', 18, 8)->nullable();
            $table->decimal('unrealized_pl', 18, 8)->nullable();
            $table->decimal('unrealized_plpc', 18, 8)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('positions');
    }
};
