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
        Schema::create('signals', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 10);
            $table->string('action');
            $table->decimal('price_at_signal', 18, 8)->nullable();
            $table->text('reason')->nullable();
            $table->decimal('confidence', 5, 2)->nullable();
            $table->boolean('executed')->default(false);
            $table->timestamps();

            $table->index(['symbol', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('signals');
    }
};
