<?php

use Illuminate\Support\Facades\Schedule;

// cPanel cron (every minute): * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
Schedule::command('dudhwala:backup')->dailyAt('21:00')->withoutOverlapping(); // 03:00 Asia/Dhaka (server time is UTC)
