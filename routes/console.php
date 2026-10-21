<?php

use App\Models\ActivityLog;
use App\Services\Inventory\ReservationService;
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

// Stock reservations past their expiry date stop holding stock.
Artisan::command('inventory:expire-reservations', function (ReservationService $reservations) {
    $this->info($reservations->expire().' reservation(s) expired.');
})->purpose('Mark stock reservations past their expiry date as expired');
Schedule::command('inventory:expire-reservations')->dailyAt('00:10');
