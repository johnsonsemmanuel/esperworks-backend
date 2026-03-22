<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use Illuminate\Console\Command;

class MarkOverdueInvoices extends Command
{
    protected $signature = 'invoices:mark-overdue';
    protected $description = 'Mark invoices past due date as overdue';

    public function handle(): int
    {
        $count = Invoice::whereIn('status', ['sent', 'viewed', 'partial', 'partially_paid'])
            ->where('due_date', '<', now()->startOfDay())
            ->update(['status' => 'overdue']);

        $this->info("Marked {$count} invoices as overdue.");
        return Command::SUCCESS;
    }
}
