<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Models\Business;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;

class SendPaymentReminders implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $retryAfter = 60;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     */
    public int $maxExceptions = 3;

    /**
     * The job timeout in seconds.
     */
    public int $timeout = 300;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $jobId = $this->job->getJobId();
        $startTime = now();

        try {
            Log::info("SendPaymentReminders job started", ['job_id' => $jobId]);

            // Check if job should run (rate limiting)
            if (!$this->shouldRun()) {
                Log::info("SendPaymentReminders job skipped - rate limited", ['job_id' => $jobId]);
                return;
            }

            $reminders = [
                7 => '7 days before due',
                3 => '3 days before due', 
                1 => '1 day before due',
            ];

            $results = [];
            foreach ($reminders as $daysBefore => $description) {
                $results[$daysBefore] = $this->sendRemindersForDaysBefore($daysBefore, $description);
            }

            // Send overdue reminders
            $results['overdue'] = $this->sendOverdueReminders();

            // Log completion
            $duration = $startTime->diffInSeconds(now());
            Log::info("SendPaymentReminders job completed", [
                'job_id' => $jobId,
                'duration_seconds' => $duration,
                'results' => $results
            ]);

            // Cache job completion for monitoring
            Cache::put('payment_reminders_last_run', now()->toDateTimeString(), 3600);

        } catch (\Exception $e) {
            Log::error("SendPaymentReminders job failed", [
                'job_id' => $jobId,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Determine if we should retry
            if ($this->shouldRetry($e)) {
                $this->release($this->retryAfter);
            } else {
                // Mark job as failed permanently
                $this->fail($e);
            }
        }
    }

    /**
     * Determine if the job should run based on rate limiting
     */
    private function shouldRun(): bool
    {
        $lastRun = Cache::get('payment_reminders_last_run');
        
        if (!$lastRun) {
            return true;
        }

        // Only run once per hour
        return now()->diffInHours($lastRun) >= 1;
    }

    /**
     * Determine if the job should be retried
     */
    private function shouldRetry(\Exception $exception): bool
    {
        // Don't retry certain exceptions
        if ($exception instanceof \Illuminate\Database\QueryException) {
            return false;
        }

        // Don't retry if we've exceeded max attempts
        if ($this->attempts() >= $this->tries) {
            return false;
        }

        // Retry for network-related exceptions
        if ($exception instanceof \Illuminate\Mail\TransportException) {
            return true;
        }

        // Retry for timeout exceptions
        if ($exception instanceof \Illuminate\Http\Client\RequestException) {
            return true;
        }

        return true;
    }

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("SendPaymentReminders job failed permanently", [
            'job_id' => $this->job->getJobId(),
            'attempts' => $this->attempts(),
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        // Notify admin of critical failure
        try {
            $this->notifyAdminOfFailure($exception);
        } catch (\Exception $e) {
            Log::error("Failed to notify admin of job failure", ['error' => $e->getMessage()]);
        }
    }

    /**
     * Notify admin of job failure
     */
    private function notifyAdminOfFailure(\Throwable $exception): void
    {
        // This would send a notification to system administrators
        // Implementation depends on your notification system
        Log::critical("CRITICAL: SendPaymentReminders job failed permanently", [
            'job_id' => $this->job->getJobId(),
            'attempts' => $this->attempts(),
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }

    private function sendRemindersForDaysBefore(int $daysBefore, string $description): array
    {
        $targetDate = now()->addDays($daysBefore)->format('Y-m-d');
        
        $invoices = Invoice::where('due_date', $targetDate)
            ->whereIn('status', ['sent', 'viewed'])
            ->whereDoesntHave('payments', function ($query) {
                $query->where('status', 'success');
            })
            ->with(['business', 'client'])
            ->get();

        $results = [
            'found' => $invoices->count(),
            'sent' => 0,
            'failed' => 0,
            'errors' => []
        ];

        foreach ($invoices as $invoice) {
            try {
                $this->sendReminder($invoice, 'upcoming', $description);
                $results['sent']++;
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'error' => $e->getMessage()
                ];
                Log::error("Failed to send reminder for invoice {$invoice->invoice_number}", [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $results;
    }

    private function sendOverdueReminders(): array
    {
        $invoices = Invoice::where('due_date', '<', now()->format('Y-m-d'))
            ->whereIn('status', ['sent', 'viewed', 'overdue'])
            ->whereDoesntHave('payments', function ($query) {
                $query->where('status', 'success');
            })
            ->with(['business', 'client'])
            ->get();

        $results = [
            'found' => $invoices->count(),
            'sent' => 0,
            'failed' => 0,
            'errors' => []
        ];

        foreach ($invoices as $invoice) {
            try {
                $this->sendReminder($invoice, 'overdue', 'overdue');
                $results['sent']++;
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'error' => $e->getMessage()
                ];
                Log::error("Failed to send overdue reminder for invoice {$invoice->invoice_number}", [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $results;
    }

    private function sendReminder(Invoice $invoice, string $type, string $description): void
    {
        // Send reminder to client
        try {
            Mail::to($invoice->client->email)->send(new \App\Mail\InvoiceMail($invoice, 'reminder'));
        } catch (\Exception $e) {
            Log::error("Failed to send client reminder", [
                'invoice_id' => $invoice->id,
                'client_email' => $invoice->client->email,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
        
        // Send notification to business
        try {
            Mail::to($invoice->business->email)->send(new \App\Mail\InvoiceMail($invoice, 'business_reminder'));
        } catch (\Exception $e) {
            Log::warning("Failed to send business reminder", [
                'invoice_id' => $invoice->id,
                'business_email' => $invoice->business->email,
                'error' => $e->getMessage()
            ]);
            // Don't throw here - business reminder failure is not critical
        }
        
        // Log the activity
        activity_log()
            ->causedByAnonymous()
            ->performedOn($invoice)
            ->log('invoice.reminder_sent', "Payment reminder sent {$description} for {$invoice->invoice_number}");
        
        Log::info("Payment reminder sent for invoice {$invoice->invoice_number} to {$invoice->client->email}");
    }
}
