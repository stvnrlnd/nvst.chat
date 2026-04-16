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
        Schema::create('news_articles', function (Blueprint $table) {
            $table->id();
            $table->string('polygon_id')->unique(); // Polygon article ID — prevents duplicates
            $table->string('symbol', 10);
            $table->string('title');
            $table->string('url');
            $table->timestamp('published_at');
            $table->string('sentiment')->nullable();          // positive | negative | neutral
            $table->text('sentiment_reasoning')->nullable();
            $table->string('author')->nullable();
            $table->timestamps();

            $table->index(['symbol', 'published_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('news_articles');
    }
};
