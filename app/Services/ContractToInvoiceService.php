<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Invoice;
use App\Services\AdminNotificationService;
use Illuminate\Support\Carbon;

class ContractToInvoiceService
{
    public function __construct(
        private InvoiceService $invoiceService
    ) {}

    /**
     * Create a draft invoice from an accepted proposal or signed contract when eligible.
     * Does nothing if contract already has a linked invoice or business cannot create invoices.
     */
    public function createDraftInvoiceIfEligible(Contract $contract): ?Invoice
    {
        $contract->load(['business', 'client']);

        if ($contract->invoices()->exists()) {
            return null;
        }

        if (!$contract->business->canCreateInvoice()) {
            return null;
        }

        $invoice = $this->invoiceService->createDraftFromContract($contract);
        if (!$invoice) {
            return null;
        }

        $this->notifyBusinessForReview($contract, $invoice);

        return $invoice;
    }

    private function notifyBusinessForReview(Contract $contract, Invoice $invoice): void
    {
        $business = $contract->business;
        $owner = $business->owner;
        if (!$owner) {
            return;
        }

        $docType = $contract->type === 'proposal' ? 'Proposal' : 'Contract';
        $ref = $contract->contract_number ?? ('#' . $contract->id);

        AdminNotificationService::create(
            'invoice.draft_from_document',
            $docType . ' accepted — Review & send invoice',
            "{$docType} {$ref} has been " . ($contract->type === 'proposal' ? 'accepted' : 'signed') . ". A draft invoice has been created. Review and send it when ready.",
            [
                'contract_id' => $contract->id,
                'contract_number' => $contract->contract_number,
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'business_id' => $business->id,
            ],
            $owner->id,
            $business->id
        );
    }
}
