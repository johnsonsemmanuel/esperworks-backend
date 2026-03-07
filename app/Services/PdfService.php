<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Contract;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class PdfService
{
    public function generateInvoicePdf(Invoice $invoice): string
    {
        $invoice->load(['business', 'client', 'items']);

        $data = $this->prepareInvoiceData($invoice);

        $pdf = Pdf::loadView('pdf.invoice', $data);

        $pdf->setPaper('A4')
            ->setOptions([
                'defaultFont' => 'Helvetica',
                'isRemoteEnabled' => true,
                'isHtml5ParserEnabled' => false,
                'isPhpEnabled' => true,
                'enable_fontsubsetting' => false,
                'dpi' => 96,
                'defaultPaperSize' => 'a4',
                'defaultPaperOrientation' => 'portrait',
                'margin-top' => 20,
                'margin-right' => 20,
                'margin-bottom' => 20,
                'margin-left' => 20,
            ]);

        $safeNumber = $this->sanitizeFilenameSegment($invoice->invoice_number ?? (string) $invoice->id);
        $filename = "invoices/{$invoice->business_id}/{$safeNumber}.pdf";
        Storage::disk('public')->put($filename, $pdf->output());

        $invoice->update(['pdf_path' => $filename]);

        return $filename;
    }

    public function generateContractPdf(Contract $contract): string
    {
        $contract->load(['business', 'client']);

        $pdf = Pdf::loadView('pdf.contract', [
            'contract' => $contract,
            'business' => $contract->business,
            'client' => $contract->client,
        ]);

        $pdf->setPaper('A4')
            ->setOptions([
                'defaultFont' => 'Arial',
                'isRemoteEnabled' => true,
                'isHtml5ParserEnabled' => false,
                'isPhpEnabled' => true,
                'enable_fontsubsetting' => false,
                'dpi' => 96,
                'defaultPaperSize' => 'a4',
                'defaultPaperOrientation' => 'portrait',
                'margin-top' => 20,
                'margin-right' => 20,
                'margin-bottom' => 20,
                'margin-left' => 20,
            ]);

        $safeNumber = $this->sanitizeFilenameSegment($contract->contract_number ?? (string) $contract->id);
        $filename = "contracts/{$contract->business_id}/{$safeNumber}.pdf";
        Storage::disk('public')->put($filename, $pdf->output());

        $contract->update(['pdf_path' => $filename]);

        return $filename;
    }

    public function streamInvoicePdf(Invoice $invoice)
    {
        $invoice->load(['business', 'client', 'items']);

        $data = $this->prepareInvoiceData($invoice);

        $safeNumber = $this->sanitizeFilenameSegment($invoice->invoice_number ?? (string) $invoice->id);
        return Pdf::loadView('pdf.invoice', $data)->stream("{$safeNumber}.pdf");
    }

    public function streamContractPdf(Contract $contract)
    {
        $contract->load(['business', 'client']);

        $safeNumber = $this->sanitizeFilenameSegment($contract->contract_number ?? (string) $contract->id);

        return Pdf::loadView('pdf.contract', [
            'contract' => $contract,
            'business' => $contract->business,
            'client' => $contract->client,
        ])->stream("{$safeNumber}.pdf");
    }

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

    /**
     * Sanitize a filename segment so it is safe for HTTP headers and file systems.
     */
    private function sanitizeFilenameSegment(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return 'document';
        }
        // Replace directory separators and other disallowed characters with dashes
        $name = str_replace(['/', '\\'], '-', $name);
        // Collapse remaining whitespace
        $name = preg_replace('/\s+/', '-', $name) ?: 'document';
        return $name;
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
            // Simple mapping fallback
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
