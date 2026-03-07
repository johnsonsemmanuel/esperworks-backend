<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Jobs\SendPaymentReminders;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Send payment reminders daily at 9 AM
        $schedule->job(new SendPaymentReminders)
            ->dailyAt('09:00')
            ->description('Send payment reminders for upcoming and overdue invoices');

        // Plan / trial expiry reminders (runs daily in the morning)
        $schedule->command('plans:send-expiry-reminders')
            ->dailyAt('08:30')
            ->description('Send plan / trial expiry reminder emails to business owners');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
    }
}
