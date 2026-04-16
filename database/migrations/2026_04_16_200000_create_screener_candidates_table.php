<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('screener_candidates', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 10)->index();
            $table->date('screened_date')->index();
            $table->decimal('score', 5, 2)->default(0);
            $table->decimal('price', 10, 4)->nullable();
            $table->decimal('atr', 10, 4)->nullable();
            $table->decimal('atr_pct', 5, 2)->nullable();
            $table->decimal('sma5', 10, 4)->nullable();
            $table->decimal('sma20', 10, 4)->nullable();
            $table->unsignedTinyInteger('up_days')->nullable()->comment('Up-days out of last 5 bars');
            $table->boolean('disqualified')->default(false);
            $table->string('disqualified_reason')->nullable();
            $table->timestamps();

            $table->unique(['symbol', 'screened_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('screener_candidates');
    }
};
