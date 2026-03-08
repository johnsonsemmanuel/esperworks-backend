<?php

namespace App\Jobs;

use App\Models\Contract;
use App\Models\Invoice;
use App\Services\PdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GeneratePdfJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;
    public int $timeout = 120;

    public function __construct(
        private string $modelType,
        private int $modelId,
    ) {}

    public function handle(PdfService $pdfService): void
    {
        try {
            if ($this->modelType === 'invoice') {
                $model = Invoice::findOrFail($this->modelId);
                $pdfService->generateInvoicePdf($model);
                Log::info("PDF generated for invoice {$model->invoice_number}");
            } elseif ($this->modelType === 'contract') {
                $model = Contract::findOrFail($this->modelId);
                $pdfService->generateContractPdf($model);
                Log::info("PDF generated for contract {$model->contract_number}");
            }
        } catch (\Throwable $e) {
            Log::error("PDF generation failed for {$this->modelType} #{$this->modelId}: {$e->getMessage()}");
            throw $e;
        }
    }

    public static function forInvoice(Invoice $invoice): self
    {
        return new self('invoice', $invoice->id);
    }

    public static function forContract(Contract $contract): self
    {
        return new self('contract', $contract->id);
    }
}
