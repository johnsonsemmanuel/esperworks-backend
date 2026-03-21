<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Business;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class InvoiceService
{
    public function createDraft(Business $business, array $data): Invoice
    {
        $invoiceNumber = $business->generateInvoiceNumber();

        // Auto-apply business signature if available and not explicitly provided
        $businessSigName = $data['business_signature_name'] ?? null;
        $businessSigImage = $data['business_signature_image'] ?? null;
        $businessSignedAt = null;

        // If no signature provided in request, use business default signature
        if (empty($businessSigName) && !empty($business->signature_name)) {
            $businessSigName = $business->signature_name;
            $businessSigImage = $business->signature_image;
            $businessSignedAt = now();
        } elseif (!empty($businessSigName)) {
            // Signature was explicitly provided in request
            $businessSignedAt = now();
        }

        $useGhanaTax = $data['use_ghana_tax'] ?? $business->use_ghana_tax ?? false;

        $invoice = Invoice::create([
            'business_id' => $business->id,
            'client_id' => $data['client_id'],
            'contract_id' => $data['contract_id'] ?? null,
            'invoice_number' => $invoiceNumber,
            'status' => 'draft',
            'issue_date' => $data['issue_date'],
            'due_date' => $data['due_date'],
            'currency' => $data['currency'] ?? $business->currency ?? 'GHS',
            'vat_rate' => $data['vat_rate'] ?? $business->vat_rate ?? 0,
            'use_ghana_tax' => $useGhanaTax,
            'nhil_rate' => $data['nhil_rate'] ?? ($useGhanaTax ? ($business->default_nhil_rate ?? 2.5) : 0),
            'getfund_rate' => $data['getfund_rate'] ?? ($useGhanaTax ? ($business->default_getfund_rate ?? 2.5) : 0),
            'covid_levy_rate' => $data['covid_levy_rate'] ?? ($useGhanaTax ? ($business->default_covid_levy_rate ?? 1.0) : 0),
            'discount_rate' => $data['discount_rate'] ?? 0,
            'notes' => $data['notes'] ?? self::defaultNotes($business, $data['due_date'] ?? null),
            'payment_method' => $data['payment_method'] ?? 'all',
            'signature_required' => $data['signature_required'] ?? true,
            'client_signature_required' => $data['client_signature_required'] ?? true,
            'business_signature_name' => $businessSigName,
            'business_signature_image' => $businessSigImage,
            'business_signed_at' => $businessSignedAt,
            'signing_token' => Str::random(64),
            'token_expires_at' => now()->addDays(30),
        ]);

        foreach ($data['items'] as $index => $item) {
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'description' => $item['description'],
                'quantity' => $item['quantity'],
                'rate' => $item['rate'],
                'amount' => $item['quantity'] * $item['rate'],
                'sort_order' => $index,
            ]);
        }

        $invoice->calculateTotals();

        app(PdfService::class)->generateInvoicePdf($invoice);

        ActivityLog::log('invoice.created', "Invoice {$invoiceNumber} created", $invoice);

        // Handle Recurring Invoice creation if requested
        if (!empty($data['is_recurring'])) {
            try {
                \App\Models\RecurringInvoice::create([
                    'business_id' => $business->id,
                    'client_id' => $data['client_id'],
                    'title' => "Recurring Invoice for " . ($invoice->client->name ?? 'Client'),
                    'currency' => $invoice->currency,
                    'subtotal' => $invoice->subtotal,
                    'vat_rate' => $invoice->vat_rate,
                    'vat_amount' => $invoice->vat_amount,
                    'total' => $invoice->total,
                    'frequency' => $data['frequency'] ?? 'monthly',
                    'interval_count' => $data['recurring_interval'] ?? 1,
                    'start_date' => $data['issue_date'],
                    'end_date' => $data['recurring_end_date'] ?? null,
                    'next_invoice_date' => $this->calculateNextDate($data['issue_date'], $data['frequency'] ?? 'monthly'),
                    'is_active' => true,
                    'notes' => $data['notes'] ?? null,
                    'items_data' => $data['items'],
                    'last_invoice_id' => $invoice->id,
                ]);
            } catch (\Exception $e) {
                \Log::error('Failed to create recurring invoice: ' . $e->getMessage());
                // We don't fail the whole request because the primary invoice was created
            }
        }

        return $invoice->load(['client', 'items']);
    }

    private function calculateNextDate($startDate, $frequency): \Carbon\Carbon
    {
        $date = \Illuminate\Support\Carbon::parse($startDate);
        return match ($frequency) {
            'daily' => $date->addDay(),
            'weekly' => $date->addWeek(),
            'biweekly' => $date->addWeeks(2),
            'monthly' => $date->addMonth(),
            'quarterly' => $date->addMonths(3),
            'yearly' => $date->addYear(),
            default => $date->addMonth(),
        };
    }

    /** Default professional notes when none provided. Clear due date, gratitude, payment expectations, footer reference. */
    public static function defaultNotes(Business $business, $dueDate = null): string
    {
        $dueStr = 'Payment is due by the date shown above.';
        if ($dueDate) {
            $d = Carbon::parse($dueDate);
            $dueStr = 'Please pay by ' . $d->format('l, F j, Y') . '. We expect payment on or before this due date.';
        }
        $contact = trim($business->email ?? '');
        $supportLine = $contact
            ? "For questions or payment help, contact us at {$contact}."
            : "For questions or payment help, please contact us using the details in the footer of this document.";
        return "Thank you for your business. We appreciate your prompt payment.\n\n"
            . $dueStr . "\n\n"
            . "If anything is unclear or you need to discuss payment options, please reach out before the due date. "
            . $supportLine . "\n\n"
            . "Payment instructions and support contact details are also in the footer of this document.";
    }

    /**
     * Create a draft invoice from a contract or accepted proposal. Used when proposal is accepted or contract is signed.
     */
    public function createDraftFromContract(Contract $contract): ?Invoice
    {
        $business = $contract->business;
        $issueDate = Carbon::today();
        $dueDays = 30;
        if ($business->payment_terms && is_numeric($business->payment_terms)) {
            $dueDays = (int) $business->payment_terms;
        } elseif (is_string($business->payment_terms) && preg_match('/\d+/', $business->payment_terms, $m)) {
            $dueDays = (int) $m[0];
        }
        $dueDate = $issueDate->copy()->addDays(max(1, $dueDays));

        $ref = $contract->contract_number ?? ('#' . $contract->id);
        $docType = $contract->type === 'proposal' ? 'Proposal' : 'Contract';
        $notes = "Thank you for your business. This invoice is for the agreed scope under {$docType} {$ref}.\n\n"
            . "Please pay by " . $dueDate->format('l, F j, Y') . ". We expect payment on or before this due date.\n\n"
            . "If you have any questions about this invoice, please contact us before the due date. "
            . "Payment instructions and support contact details are in the footer of this document.";

        $items = $this->buildItemsFromContract($contract);
        if (empty($items)) {
            return null;
        }

        $data = [
            'client_id' => $contract->client_id,
            'contract_id' => $contract->id,
            'issue_date' => $issueDate->toDateString(),
            'due_date' => $dueDate->toDateString(),
            'currency' => $business->currency ?? 'GHS',
            'vat_rate' => $business->vat_rate ?? 0,
            'notes' => $notes,
            'items' => $items,
        ];

        $invoice = $this->createDraft($business, $data);
        $invoice->load('contract');

        return $invoice;
    }

    private function buildItemsFromContract(Contract $contract): array
    {
        $milestones = $contract->milestones;
        $value = (float) $contract->value;
        $title = $contract->title ?: ($contract->type === 'proposal' ? 'Proposal' : 'Contract');

        if (is_array($milestones) && !empty($milestones)) {
            $items = [];
            foreach ($milestones as $i => $m) {
                $name = $m['name'] ?? ('Milestone ' . ($i + 1));
                $desc = $m['description'] ?? '';
                $amount = isset($m['amount']) ? (float) $m['amount'] : 0;
                if ($amount <= 0 && $value > 0 && count($milestones) === 1) {
                    $amount = $value;
                }
                $items[] = [
                    'description' => trim($name . ($desc ? " — {$desc}" : '')),
                    'quantity' => 1,
                    'rate' => $amount,
                ];
            }
            $sum = array_sum(array_map(fn ($i) => $i['quantity'] * $i['rate'], $items));
            if ($sum > 0) {
                return $items;
            }
        }

        return [
            [
                'description' => $title . ' — ' . ($contract->contract_number ?? 'Agreed scope'),
                'quantity' => 1,
                'rate' => $value > 0 ? $value : 0,
            ],
        ];
    }

    public function updateDraft(Invoice $invoice, array $data): Invoice
    {
        $updates = array_filter([
            'client_id'      => $data['client_id'] ?? null,
            'issue_date'     => $data['issue_date'] ?? null,
            'due_date'       => $data['due_date'] ?? null,
            'currency'       => $data['currency'] ?? null,
            'vat_rate'       => $data['vat_rate'] ?? null,
            'use_ghana_tax'  => $data['use_ghana_tax'] ?? null,
            'nhil_rate'      => $data['nhil_rate'] ?? null,
            'getfund_rate'   => $data['getfund_rate'] ?? null,
            'covid_levy_rate'=> $data['covid_levy_rate'] ?? null,
            'discount_rate'  => $data['discount_rate'] ?? null,
            'notes'          => $data['notes'] ?? null,
            'payment_method' => $data['payment_method'] ?? null,
        ], static fn ($value) => $value !== null);
        $invoice->update($updates);

        // Auto-apply business signature if not already set
        if (empty($invoice->business_signature_name) && !empty($invoice->business->signature_name)) {
            $invoice->update([
                'business_signature_name' => $invoice->business->signature_name,
                'business_signature_image' => $invoice->business->signature_image,
                'business_signed_at' => now(),
            ]);
        }

        if (array_key_exists('items', $data)) {
            $invoice->items()->delete();

            foreach ($data['items'] as $index => $item) {
                $rate = $item['rate'] ?? $item['unit_price'] ?? 0;
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'rate' => $rate,
                    'amount' => $item['quantity'] * $rate,
                    'sort_order' => $index,
                ]);
            }

            $invoice->calculateTotals();
        }

        app(PdfService::class)->generateInvoicePdf($invoice);

        return $invoice->load(['client', 'items']);
    }
}

