<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Contract;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\Browsershot\Browsershot;

class PdfService
{
    /**
     * Check if Browsershot (Chromium) is available in this environment.
     */
    private function browsershotAvailable(): bool
    {
        $chromiumPath = env('CHROMIUM_PATH', env('PUPPETEER_EXECUTABLE_PATH', '/usr/bin/chromium'));
        return !empty($chromiumPath) && (file_exists($chromiumPath) || file_exists('/usr/bin/chromium-browser'));
    }

    /**
     * Create a configured Browsershot instance from rendered HTML.
     */
    private function makeBrowsershot(string $html): Browsershot
    {
        $chromiumPath = env('CHROMIUM_PATH', env('PUPPETEER_EXECUTABLE_PATH', '/usr/bin/chromium'));

        // Fallback to common paths
        if (!file_exists($chromiumPath)) {
            foreach (['/usr/bin/chromium-browser', '/usr/bin/google-chrome', '/usr/bin/chromium'] as $path) {
                if (file_exists($path)) {
                    $chromiumPath = $path;
                    break;
                }
            }
        }

        $shot = Browsershot::html($html)
            ->setChromePath($chromiumPath)
            ->noSandbox()
            ->disableGpu()
            ->format('A4')
            ->showBackground()
            ->waitUntilNetworkIdle()
            ->setOption('args', ['--disable-dev-shm-usage', '--disable-setuid-sandbox']);

        // Use npm-installed puppeteer if available
        $npmGlobalPath = trim(shell_exec('npm root -g 2>/dev/null') ?? '');
        if ($npmGlobalPath && is_dir($npmGlobalPath . '/puppeteer')) {
            $shot->setNodeModulePath($npmGlobalPath);
        }

        return $shot;
    }

    // ──────────────────────────────────────────────────────
    //  INVOICE PDF — Browsershot with DomPDF fallback
    // ──────────────────────────────────────────────────────

    public function generateInvoicePdf(Invoice $invoice): string
    {
        $invoice->load(['business', 'client', 'items']);
        $data = $this->prepareInvoiceData($invoice);

        $safeNumber = $this->sanitizeFilenameSegment($invoice->invoice_number ?? (string) $invoice->id);
        $filename = "invoices/{$invoice->business_id}/{$safeNumber}.pdf";

        try {
            if ($this->browsershotAvailable()) {
                $html = view('pdf.invoice-pro', $data)->render();
                $pdfContent = $this->makeBrowsershot($html)->pdf();
            } else {
                $pdfContent = $this->generateDomPdfContent('pdf.invoice', $data);
            }
        } catch (\Throwable $e) {
            Log::warning('Browsershot invoice generation failed, falling back to DomPDF', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
            $pdfContent = $this->generateDomPdfContent('pdf.invoice', $data);
        }

        Storage::disk('public')->put($filename, $pdfContent);
        $invoice->update(['pdf_path' => $filename]);

        return $filename;
    }

    public function streamInvoicePdf(Invoice $invoice)
    {
        $invoice->load(['business', 'client', 'items']);
        $data = $this->prepareInvoiceData($invoice);
        $safeNumber = $this->sanitizeFilenameSegment($invoice->invoice_number ?? (string) $invoice->id);

        try {
            if ($this->browsershotAvailable()) {
                $html = view('pdf.invoice-pro', $data)->render();
                $pdfContent = $this->makeBrowsershot($html)->pdf();
                return $this->streamFromContent($pdfContent, "{$safeNumber}.pdf");
            }
        } catch (\Throwable $e) {
            Log::warning('Browsershot invoice stream failed, falling back to DomPDF', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }

        return Pdf::loadView('pdf.invoice', $data)->stream("{$safeNumber}.pdf");
    }

    // ──────────────────────────────────────────────────────
    //  CONTRACT PDF — Browsershot with DomPDF fallback
    // ──────────────────────────────────────────────────────

    public function generateContractPdf(Contract $contract): string
    {
        $contract->load(['business', 'client']);
        $data = $this->prepareContractData($contract);

        $safeNumber = $this->sanitizeFilenameSegment($contract->contract_number ?? (string) $contract->id);
        $filename = "contracts/{$contract->business_id}/{$safeNumber}.pdf";

        try {
            if ($this->browsershotAvailable()) {
                $html = view('pdf.contract-pro', $data)->render();
                $pdfContent = $this->makeBrowsershot($html)->pdf();
            } else {
                $pdfContent = $this->generateDomPdfContent('pdf.contract', $data);
            }
        } catch (\Throwable $e) {
            Log::warning('Browsershot contract generation failed, falling back to DomPDF', [
                'contract_id' => $contract->id,
                'error' => $e->getMessage(),
            ]);
            $pdfContent = $this->generateDomPdfContent('pdf.contract', $data);
        }

        Storage::disk('public')->put($filename, $pdfContent);
        $contract->update(['pdf_path' => $filename]);

        return $filename;
    }

    public function streamContractPdf(Contract $contract)
    {
        $contract->load(['business', 'client']);
        $data = $this->prepareContractData($contract);
        $safeNumber = $this->sanitizeFilenameSegment($contract->contract_number ?? (string) $contract->id);

        try {
            if ($this->browsershotAvailable()) {
                $html = view('pdf.contract-pro', $data)->render();
                $pdfContent = $this->makeBrowsershot($html)->pdf();
                return $this->streamFromContent($pdfContent, "{$safeNumber}.pdf");
            }
        } catch (\Throwable $e) {
            Log::warning('Browsershot contract stream failed, falling back to DomPDF', [
                'contract_id' => $contract->id,
                'error' => $e->getMessage(),
            ]);
        }

        return Pdf::loadView('pdf.contract', $data)->stream("{$safeNumber}.pdf");
    }

    // ──────────────────────────────────────────────────────
    //  RECEIPT PDF — DomPDF only (lightweight, no Chromium needed)
    // ──────────────────────────────────────────────────────

    public function streamReceiptPdf(\App\Models\Payment $payment)
    {
        $payment->load(['invoice.business', 'invoice.client', 'client']);

        $invoice = $payment->invoice;
        $business = $invoice ? $invoice->business : null;
        $client = $payment->client ?? ($invoice ? $invoice->client : null);

        $safeReference = $this->sanitizeFilenameSegment($payment->reference ?? ('payment-' . $payment->id));

        return Pdf::loadView('pdf.receipt', [
            'payment' => $payment,
            'invoice' => $invoice,
            'business' => $business,
            'client' => $client,
        ])->stream("receipt-{$safeReference}.pdf");
    }

    // ──────────────────────────────────────────────────────
    //  HELPERS
    // ──────────────────────────────────────────────────────

    /**
     * Generate PDF content using DomPDF (fallback engine).
     */
    private function generateDomPdfContent(string $view, array $data): string
    {
        $pdf = Pdf::loadView($view, $data);
        $pdf->setPaper('A4')
            ->setOptions([
                'defaultFont' => 'Helvetica',
                'isRemoteEnabled' => true,
                'isHtml5ParserEnabled' => false,
                'isPhpEnabled' => true,
                'dpi' => 96,
            ]);
        return $pdf->output();
    }

    /**
     * Stream raw PDF bytes as an HTTP response.
     */
    private function streamFromContent(string $pdfContent, string $filename): \Illuminate\Http\Response
    {
        return response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
            'Content-Length' => strlen($pdfContent),
        ]);
    }

    /**
     * Sanitize a filename segment so it is safe for HTTP headers and file systems.
     */
    private function sanitizeFilenameSegment(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return 'document';
        }
        $name = str_replace(['/', '\\'], '-', $name);
        $name = preg_replace('/\s+/', '-', $name) ?: 'document';
        return $name;
    }

    private function prepareContractData(Contract $contract): array
    {
        return [
            'contract' => $contract,
            'business' => $contract->business,
            'client' => $contract->client,
        ];
    }

    private function prepareInvoiceData(Invoice $invoice): array
    {
        $business = $invoice->business;
        $client = $invoice->client;

        $issueDate = $invoice->issue_date
            ? \Illuminate\Support\Carbon::parse($invoice->issue_date)
            : ($invoice->date ? \Illuminate\Support\Carbon::parse($invoice->date) : null);
        $dueDate = $invoice->due_date ? \Illuminate\Support\Carbon::parse($invoice->due_date) : null;
        $logoPath = !empty($business->logo) ? storage_path('app/public/' . $business->logo) : null;

        return [
            'invoice' => $invoice,
            'business' => $business,
            'client' => $client,
            'items' => $invoice->items,
            'issueDate' => $issueDate,
            'dueDate' => $dueDate,
            'logoPath' => $logoPath,
            'businessSignature' => $this->prepareSignature(
                $invoice->business_signature_image,
                $invoice->business_signature_name
            ),
            'clientSignature' => $this->prepareSignature(
                $invoice->client_signature_image,
                $invoice->client_signature_name
            ),
        ];
    }

    private function prepareSignature($image, $name)
    {
        if (empty($image)) {
            return null;
        }

        if (\Illuminate\Support\Str::startsWith($image, 'typed:')) {
            $parts = explode(':', $image);
            $font = $parts[1] ?? 'font-serif';
            $sigName = $parts[2] ?? $name;
            
            $cssClass = 'sig-text ' . str_replace(['font-', ' '], ['', ' '], $font);
            if (strpos($font, 'serif') !== false) $cssClass .= ' font-serif';
            if (strpos($font, 'sans') !== false) $cssClass .= ' font-sans';
            if (strpos($font, 'mono') !== false) $cssClass .= ' font-mono';
            if (strpos($font, 'italic') !== false) $cssClass .= ' italic';
            if (strpos($font, 'bold') !== false) $cssClass .= ' font-bold';

            return [
                'type' => 'typed',
                'name' => $sigName,
                'cssClass' => $cssClass,
            ];
        } elseif (\Illuminate\Support\Str::startsWith($image, 'data:image')) {
             return [
                'type' => 'base64',
                'image' => $image,
            ];
        } else {
            $path = storage_path('app/public/' . $image);
            if (file_exists($path)) {
                return [
                    'type' => 'file',
                    'path' => $path,
                ];
            }
        }
        return null;
    }
}
