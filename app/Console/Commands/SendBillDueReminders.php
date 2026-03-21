<?php

namespace App\Console\Commands;

use App\Models\Bill;
use App\Models\Business;
use App\Services\SmsService;
use Illuminate\Console\Command;

/**
 * Runs daily at 08:30. Sends an SMS to each business owner for every bill
 * that is due tomorrow and not yet paid/cancelled.
 *
 * SMS is plan-gated via SmsService — if the business is out of quota the
 * reminder is silently skipped (the business was already notified by email).
 */
class SendBillDueReminders extends Command
{
    protected $signature   = 'bills:send-due-reminders';
    protected $description = 'Send SMS reminders to business owners for bills due tomorrow';

    public function handle(): int
    {
        $tomorrow = now()->addDay()->toDateString();

        $bills = Bill::with('business.user')
            ->whereDate('due_date', $tomorrow)
            ->whereNotIn('status', ['paid', 'cancelled'])
            ->get();

        $sent = 0;

        foreach ($bills as $bill) {
            $business = $bill->business;
            if (!$business) continue;

            // Get the business owner's phone number
            $owner = $business->user;
            if (!$owner || empty($owner->phone)) continue;

            $currency = $bill->currency ?? 'GHS';
            $symbol   = match ($currency) { 'GHS' => 'GH₵', 'USD' => '$', 'EUR' => '€', 'GBP' => '£', default => $currency . ' ' };
            $message  = "EsperWorks: Bill #{$bill->bill_number} from {$bill->supplier_name} "
                . "({$symbol}" . number_format($bill->amountDue(), 2) . ") "
                . "is due TOMORROW. Log in to mark it paid.";

            if (SmsService::send($business, $owner->phone, $message)) {
                $sent++;
            }
        }

        $this->info("Sent {$sent} bill due reminder(s) for {$bills->count()} bills due tomorrow.");
        return Command::SUCCESS;
    }
}
