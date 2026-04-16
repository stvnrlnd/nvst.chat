<?php

use App\Jobs\SyncPortfolioJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Run the full trading cycle every 5 minutes during US market hours (Mon–Fri, 9:30am–4pm ET)
Schedule::command('trading:run')
    ->everyFiveMinutes()
    ->weekdays()
    ->between('9:30', '16:00')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->runInBackground();

// Sync portfolio state every minute during market hours to keep UI current
Schedule::job(new SyncPortfolioJob)
    ->everyMinute()
    ->weekdays()
    ->between('9:30', '16:05')
    ->timezone('America/New_York')
    ->withoutOverlapping();
