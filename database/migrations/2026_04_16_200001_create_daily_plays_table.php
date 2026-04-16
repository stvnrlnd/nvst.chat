<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_plays', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 10)->index();
            $table->date('date')->unique()->comment('Only one ORB play per trading day');
            $table->string('status')->default('pending')->comment('pending, entered, exited, skipped');
            $table->string('skip_reason')->nullable();
            $table->foreignId('entry_signal_id')->nullable()->constrained('signals')->nullOnDelete();
            $table->foreignId('exit_signal_id')->nullable()->constrained('signals')->nullOnDelete();
            $table->timestamp('entered_at')->nullable();
            $table->timestamp('exited_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_plays');
    }
};
