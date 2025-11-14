<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule subscription updates to run every 10 seconds
Schedule::command('subscriptions:update')->everyTenSeconds();

// Schedule appointment reminders to run daily at 8:00 AM
Schedule::command('appointments:send-reminders')->everyTenSeconds();
