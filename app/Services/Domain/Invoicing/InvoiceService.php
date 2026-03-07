<?php

namespace App\Services\Domain\Invoicing;

use App\Models\Invoice;
use App\Models\Business;
use App\Models\Client;
use App\Repositories\Interfaces\InvoiceRepositoryInterface;
use App\Repositories\Interfaces\ClientRepositoryInterface;
use App\Services\Infrastructure\Email\EmailService;
use App\Services\Infrastructure\Storage\FileService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InvoiceService
{
    public function __construct(
        private InvoiceRepositoryInterface $invoiceRepository,
        private ClientRepositoryInterface $clientRepository,
        private EmailService $emailService,
        private FileService $fileService
    ) {}

    public function createInvoice(int $businessId, array $data): Invoice
    {
        return DB::transaction(function () use ($businessId, $data) {
            // Validate business exists and user has permission
            $business = Business::findOrFail($businessId);
            
            // Check plan limits
            $this->checkPlanLimits($business);

            // Generate invoice number
            $invoiceNumber = $this->generateInvoiceNumber($business);

            // Create invoice
            $invoice = $this->invoiceRepository->create([
                'business_id' => $businessId,
                'client_id' => $data['client_id'],
                'invoice_number' => $invoiceNumber,
                'issue_date' => $data['issue_date'] ?? now()->toDateString(),
                'due_date' => $data['due_date'],
                'currency' => $data['currency'] ?? $business->currency,
                'notes' => $data['notes'] ?? null,
                'status' => 'draft',
                'subtotal' => $this->calculateSubtotal($data['items'] ?? []),
                'tax_rate' => $data['vat_rate'] ?? 0,
                'tax_amount' => $this->calculateTax($data['items'] ?? [], $data['vat_rate'] ?? 0),
                'total' => $this->calculateTotal($data['items'] ?? [], $data['vat_rate'] ?? 0),
            ]);

            // Create invoice items
            $this->createInvoiceItems($invoice, $data['items'] ?? []);

            return $invoice->load(['client', 'items']);
        });
    }

    public function updateInvoice(int $id, int $businessId, array $data): Invoice
    {
        $invoice = $this->invoiceRepository->findByIdAndBusinessId($id, $businessId);
        
        if (!$invoice) {
            throw new \InvalidArgumentException('Invoice not found');
        }

        if ($invoice->status !== 'draft') {
            throw new \InvalidArgumentException('Only draft invoices can be edited');
        }

        return DB::transaction(function () use ($invoice, $data) {
            // Update invoice
            $invoice->update([
                'client_id' => $data['client_id'] ?? $invoice->client_id,
                'issue_date' => $data['issue_date'] ?? $invoice->issue_date,
                'due_date' => $data['due_date'] ?? $invoice->due_date,
                'currency' => $data['currency'] ?? $invoice->currency,
                'notes' => $data['notes'] ?? $invoice->notes,
                'subtotal' => $this->calculateSubtotal($data['items'] ?? []),
                'tax_rate' => $data['vat_rate'] ?? $invoice->tax_rate,
                'tax_amount' => $this->calculateTax($data['items'] ?? [], $data['vat_rate'] ?? $invoice->tax_rate),
                'total' => $this->calculateTotal($data['items'] ?? [], $data['vat_rate'] ?? $invoice->tax_rate),
            ]);

            // Update invoice items
            $invoice->items()->delete();
            $this->createInvoiceItems($invoice, $data['items'] ?? []);

            return $invoice->fresh(['client', 'items']);
        });
    }

    public function deleteInvoice(int $id, int $businessId): void
    {
        $invoice = $this->invoiceRepository->findByIdAndBusinessId($id, $businessId);
        
        if (!$invoice) {
            throw new \InvalidArgumentException('Invoice not found');
        }

        if ($invoice->status !== 'draft') {
            throw new \InvalidArgumentException('Only draft invoices can be deleted');
        }

        $invoice->delete();
    }

    public function sendInvoice(int $id, int $businessId): void
    {
        $invoice = $this->invoiceRepository->findByIdAndBusinessId($id, $businessId);
        
        if (!$invoice) {
            throw new \InvalidArgumentException('Invoice not found');
        }

        if ($invoice->status !== 'draft') {
            throw new \InvalidArgumentException('Only draft invoices can be sent');
        }

        DB::transaction(function () use ($invoice) {
            // Update status
            $invoice->update(['status' => 'sent', 'sent_at' => now()]);

            // Send email
            $this->emailService->sendInvoice($invoice);
        });
    }

    public function markAsPaid(int $id, int $businessId, array $data): void
    {
        $invoice = $this->invoiceRepository->findByIdAndBusinessId($id, $businessId);
        
        if (!$invoice) {
            throw new \InvalidArgumentException('Invoice not found');
        }

        if ($invoice->status === 'paid') {
            throw new \InvalidArgumentException('Invoice is already marked as paid');
        }

        DB::transaction(function () use ($invoice, $data) {
            // Create payment record
            $invoice->payments()->create([
                'amount' => $data['amount'],
                'payment_method' => $data['payment_method'] ?? 'manual',
                'payment_date' => $data['payment_date'] ?? now()->toDateString(),
                'notes' => $data['notes'] ?? null,
                'status' => 'success',
                'reference' => $data['reference'] ?? null,
            ]);

            // Update invoice status
            $totalPaid = $invoice->payments()->where('status', 'success')->sum('amount');
            
            if ($totalPaid >= $invoice->total) {
                $invoice->update([
                    'status' => 'paid',
                    'paid_at' => now(),
                    'amount_paid' => $totalPaid,
                ]);
            } else {
                $invoice->update([
                    'status' => 'partially_paid',
                    'amount_paid' => $totalPaid,
                ]);
            }
        });
    }

    public function generatePdf(int $id, int $businessId)
    {
        $invoice = $this->invoiceRepository->findByIdAndBusinessId($id, $businessId);
        
        if (!$invoice) {
            throw new \InvalidArgumentException('Invoice not found');
        }

        return $this->fileService->generateInvoicePdf($invoice);
    }

    public function duplicateInvoice(int $id, int $businessId): Invoice
    {
        $originalInvoice = $this->invoiceRepository->findByIdAndBusinessId($id, $businessId);
        
        if (!$originalInvoice) {
            throw new \InvalidArgumentException('Invoice not found');
        }

        return DB::transaction(function () use ($originalInvoice, $businessId) {
            // Check plan limits
            $business = Business::findOrFail($businessId);
            $this->checkPlanLimits($business);

            // Generate new invoice number
            $newInvoiceNumber = $this->generateInvoiceNumber($business);

            // Create duplicate
            $newInvoice = $this->invoiceRepository->create([
                'business_id' => $businessId,
                'client_id' => $originalInvoice->client_id,
                'invoice_number' => $newInvoiceNumber,
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'currency' => $originalInvoice->currency,
                'notes' => $originalInvoice->notes,
                'status' => 'draft',
                'subtotal' => $originalInvoice->subtotal,
                'tax_rate' => $originalInvoice->tax_rate,
                'tax_amount' => $originalInvoice->tax_amount,
                'total' => $originalInvoice->total,
            ]);

            // Duplicate items
            foreach ($originalInvoice->items as $item) {
                $newInvoice->items()->create([
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'rate' => $item->rate,
                    'total' => $item->total,
                ]);
            }

            return $newInvoice->load(['client', 'items']);
        });
    }

    private function generateInvoiceNumber(Business $business): string
    {
        $prefix = $business->invoice_prefix ?? 'INV';
        $sequence = $business->invoices()->count() + 1;
        
        return sprintf('%s-%04d', $prefix, $sequence);
    }

    private function calculateSubtotal(array $items): float
    {
        return collect($items)->sum(function ($item) {
            return ($item['quantity'] ?? 1) * ($item['rate'] ?? 0);
        });
    }

    private function calculateTax(array $items, float $taxRate): float
    {
        $subtotal = $this->calculateSubtotal($items);
        return $subtotal * ($taxRate / 100);
    }

    private function calculateTotal(array $items, float $taxRate): float
    {
        $subtotal = $this->calculateSubtotal($items);
        $tax = $this->calculateTax($items, $taxRate);
        return $subtotal + $tax;
    }

    private function createInvoiceItems(Invoice $invoice, array $items): void
    {
        foreach ($items as $item) {
            $invoice->items()->create([
                'description' => $item['description'],
                'quantity' => $item['quantity'] ?? 1,
                'rate' => $item['rate'],
                'total' => ($item['quantity'] ?? 1) * $item['rate'],
            ]);
        }
    }

    private function checkPlanLimits(Business $business): void
    {
        $limits = $business->getPlanLimits();
        $usage = $business->getUsageStats();

        if ($limits['invoices'] !== -1 && $usage['invoices']['used'] >= $limits['invoices']) {
            throw new \InvalidArgumentException('Invoice limit exceeded for your plan');
        }
    }
}
