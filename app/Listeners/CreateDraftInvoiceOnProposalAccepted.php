<?php

namespace App\Listeners;

use App\Events\ClientContractResponse;
use App\Models\Contract;
use App\Services\ContractToInvoiceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class CreateDraftInvoiceOnProposalAccepted implements ShouldQueue
{
    public function __construct(
        private ContractToInvoiceService $contractToInvoiceService
    ) {}

    /**
     * When a proposal is accepted or a contract is fully signed, create a draft invoice for business review.
     * Proposal: status change to accepted fires this with action 'accepted'.
     * Contract: fully signed (e.g. via signByToken or accept+sign) — create draft once.
     */
    public function handle(ClientContractResponse $event): void
    {
        if ($event->action !== 'accepted') {
            return;
        }

        $contract = Contract::find($event->contractId);
        if (!$contract) {
            return;
        }

        $contract->refresh();

        if ($contract->type === 'proposal') {
            // Proposal accepted → create draft (business will review & send).
            $this->createDraftIfEligible($contract);
            return;
        }

        if ($contract->type === 'contract' && $contract->status === 'signed') {
            // Contract fully signed → create draft (no duplicate: createDraftInvoiceIfEligible checks existing).
            $this->createDraftIfEligible($contract);
        }
    }

    private function createDraftIfEligible(Contract $contract): void
    {
        try {
            $this->contractToInvoiceService->createDraftInvoiceIfEligible($contract);
        } catch (\Throwable $e) {
            Log::error('CreateDraftInvoiceOnProposalAccepted failed: ' . $e->getMessage(), [
                'contract_id' => $contract->id,
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
