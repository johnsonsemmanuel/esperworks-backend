<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Business;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;
use App\Mail\PlanExpiringMail;

class SendPlanExpiryReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'plans:send-expiry-reminders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send plan / trial expiry reminder emails based on trial_ends_at and active subscriptions';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Scanning businesses for upcoming plan or trial expiries…');

        $today = now()->startOfDay();

        // Remind 3 days before and on the same day
        $daysThresholds = [3, 1];

        foreach ($daysThresholds as $days) {
            $targetDate = $today->copy()->addDays($days);

            $businesses = Business::whereDate('trial_ends_at', $targetDate->toDateString())->get();

            foreach ($businesses as $business) {
                $owner = $business->owner;
                if (!$owner || !$owner->email) {
                    continue;
                }

                $cacheKey = sprintf('plan_expiring_mail:%d:%s', $business->id, $targetDate->toDateString());
                if (Cache::has($cacheKey)) {
                    continue;
                }

                try {
                    $planName = \App\Models\Business::planDisplayName($business->plan ?? 'free');
                    Mail::to($owner->email)->queue(
                        new PlanExpiringMail($business, $planName, $days, true)
                    );
                    Cache::put($cacheKey, true, $targetDate->copy()->endOfDay());
                    $this->info("Sent trial expiry reminder to business #{$business->id} ({$owner->email})");
                } catch (\Throwable $e) {
                    $this->error("Failed to send reminder for business #{$business->id}: {$e->getMessage()}");
                }
            }
        }

        return self::SUCCESS;
    }
}

