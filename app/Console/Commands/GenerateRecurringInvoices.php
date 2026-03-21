<?php

namespace App\Console\Commands;

use App\Models\RecurringInvoice;
use App\Services\InvoiceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateRecurringInvoices extends Command
{
    protected $signature   = 'invoices:generate-recurring';
    protected $description = 'Generate invoices due today from active recurring invoice schedules';

    public function handle(InvoiceService $service): int
    {
        $today = now()->startOfDay();

        $due = RecurringInvoice::query()
            ->where('is_active', true)
            ->whereDate('next_invoice_date', '<=', $today)
            ->with(['business', 'client'])
            ->get();

        $generated = 0;
        $skipped   = 0;

        foreach ($due as $recurring) {
            try {
                // Stop if schedule has expired or hit max
                if ($recurring->shouldDeactivate()) {
                    $recurring->update(['is_active' => false]);
                    $this->line("Deactivated: #{$recurring->id} ({$recurring->title})");
                    continue;
                }

                // Skip if client or business is missing / suspended
                if (!$recurring->business || !$recurring->client) {
                    $skipped++;
                    continue;
                }

                if (($recurring->business->status ?? 'active') !== 'active') {
                    $skipped++;
                    continue;
                }

                $items = $recurring->items_data ?? [];
                if (empty($items)) {
                    $this->warn("No items on recurring invoice #{$recurring->id}, skipping.");
                    $skipped++;
                    continue;
                }

                // Build invoice data from the recurring template
                $dueDate = $today->copy()->addDays(30); // default net-30

                $data = [
                    'client_id'  => $recurring->client_id,
                    'issue_date' => $today->toDateString(),
                    'due_date'   => $dueDate->toDateString(),
                    'currency'   => $recurring->currency ?? $recurring->business->currency ?? 'GHS',
                    'vat_rate'   => $recurring->vat_rate ?? 0,
                    'notes'      => $recurring->notes,
                    'items'      => $items,
                ];

                $invoice = $service->createDraft($recurring->business, $data);

                // Advance the schedule
                $nextDate = $recurring->calculateNextDate();
                $recurring->update([
                    'last_invoice_id'  => $invoice->id,
                    'invoices_created' => ($recurring->invoices_created ?? 0) + 1,
                    'next_invoice_date'=> $nextDate->toDateString(),
                    'is_active'        => !$recurring->shouldDeactivate(),
                ]);

                $generated++;
                $this->info("Generated invoice {$invoice->invoice_number} from recurring #{$recurring->id}");

            } catch (\Throwable $e) {
                Log::error("GenerateRecurringInvoices: failed for recurring #{$recurring->id}", [
                    'error' => $e->getMessage(),
                ]);
                $this->error("Failed for #{$recurring->id}: {$e->getMessage()}");
                $skipped++;
            }
        }

        $this->info("Done — generated: {$generated}, skipped: {$skipped}");
        return self::SUCCESS;
    }
}
