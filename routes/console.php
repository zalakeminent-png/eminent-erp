<?php

use App\Models\ActivityLog;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Applies ACTIVITY_LOG_RETENTION_DAYS (no-op when retention is unset).
Schedule::command('model:prune', ['--model' => [ActivityLog::class]])->daily();

// Expired password reset / invitation tokens.
Schedule::command('auth:clear-resets')->everyFifteenMinutes();
