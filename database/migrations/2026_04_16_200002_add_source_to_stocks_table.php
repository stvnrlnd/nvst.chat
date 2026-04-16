<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            $table->string('source')->default('manual')->after('is_active')
                ->comment('manual = user-added; auto = discovered by SyncAutoWatchlistJob');
            $table->timestamp('last_seen_at')->nullable()->after('source')
                ->comment('Set each time an auto symbol appears in Alpaca most-actives/movers; null for manual');
        });
    }

    public function down(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            $table->dropColumn(['source', 'last_seen_at']);
        });
    }
};
