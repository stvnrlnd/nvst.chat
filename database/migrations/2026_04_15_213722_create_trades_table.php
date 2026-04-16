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
        Schema::create('trades', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 10);
            $table->string('side');
            $table->decimal('qty', 18, 8);
            $table->string('order_type');
            $table->string('status');
            $table->decimal('filled_avg_price', 18, 8)->nullable();
            $table->decimal('filled_qty', 18, 8)->nullable();
            $table->string('alpaca_order_id')->nullable()->unique();
            $table->foreignId('signal_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('filled_at')->nullable();
            $table->timestamps();

            $table->index(['symbol', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trades');
    }
};
