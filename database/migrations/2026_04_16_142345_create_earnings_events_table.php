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
        Schema::create('earnings_events', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 10);
            $table->date('report_date');
            $table->timestamp('refreshed_at')->nullable();
            $table->timestamps();

            $table->unique(['symbol', 'report_date']);
            $table->index('symbol');
            $table->index('report_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('earnings_events');
    }
};
