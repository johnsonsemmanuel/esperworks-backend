<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('invoices:mark-overdue')->daily()->at('00:05');
Schedule::command('invoices:generate-recurring')->daily()->at('06:00');
Schedule::command('plans:send-expiry-reminders')->daily()->at('08:00');
Schedule::command('bills:send-due-reminders')->daily()->at('08:30');
