<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Business;
use App\Models\ActivityLog;
use App\Mail\ContractMail;
use App\Services\PdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ContractController extends Controller
{
    public function index(Request $request, Business $business)
    {
        $query = $business->contracts()->with('client:id,name,email');

        if ($request->type && $request->type !== 'all') {
            $query->where('type', $request->type);
        }
        if ($request->status && $request->status !== 'all') {
            $query->where('status', $request->status);
        }
        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('title', 'like', "%{$request->search}%")
                  ->orWhere('contract_number', 'like', "%{$request->search}%")
                  ->orWhereHas('client', fn($q2) => $q2->where('name', 'like', "%{$request->search}%"));
            });
        }

        $contracts = $query->latest()->paginate($request->per_page ?? 15);

        return response()->json($contracts);
    }

    public function store(Request $request, Business $business)
    {
        $request->validate([
            'client_id' => ['required', 'exists:clients,id', function ($attribute, $value, $fail) use ($business) {
                if (!$business->clients()->where('id', $value)->exists()) {
                    $fail('The selected client does not belong to this business.');
                }
            }],
            'title' => 'required|string|max:255',
            'type' => 'required|in:contract,proposal',
            'content' => 'nullable|string',
            'value' => 'required|numeric|min:0',
            'created_date' => 'required|date',
            'expiry_date' => 'required|date',
            'business_signature_name' => 'nullable|string',
            'business_signature_image' => 'nullable|string',
            'industry_type' => 'nullable|string|max:80',
            'pricing_type' => 'nullable|string|in:fixed,hourly,milestone_based',
            'scope_of_work' => 'nullable|array',
            'scope_of_work.service_description' => 'nullable|string',
            'scope_of_work.deliverables' => 'nullable|array',
            'scope_of_work.deliverables.*' => 'nullable|string',
            'scope_of_work.exclusions' => 'nullable|string',
            'milestones' => 'nullable|array',
            'milestones.*.name' => 'nullable|string',
            'milestones.*.description' => 'nullable|string',
            'milestones.*.due_date' => 'nullable|string',
            'milestones.*.amount' => 'nullable|numeric',
            'payment_terms' => 'nullable|array',
            'payment_terms.schedule_text' => 'nullable|string',
            'payment_terms.late_payment_clause' => 'nullable|string',
            'ownership_rights' => 'nullable|array',
            'ownership_rights.client_owns_deliverables' => 'nullable|boolean',
            'ownership_rights.freelancer_portfolio_rights' => 'nullable|boolean',
            'ownership_rights.ip_after_payment' => 'nullable|boolean',
            'confidentiality_enabled' => 'nullable|boolean',
            'termination_notice_days' => 'nullable|integer|min:0|max:365',
            'termination_payment_note' => 'nullable|string|max:500',
            'introduction_message' => 'nullable|string',
            'problem_solution' => 'nullable|array',
            'problem_solution.client_problem' => 'nullable|string',
            'problem_solution.your_solution' => 'nullable|string',
            'packages' => 'nullable|array',
            'packages.*.name' => 'nullable|string',
            'packages.*.description' => 'nullable|string',
            'packages.*.price' => 'nullable|numeric',
            'packages.*.deliverables' => 'nullable|array',
            'packages.*.deliverables.*' => 'nullable|string',
            'add_ons' => 'nullable|array',
            'add_ons.*.label' => 'nullable|string',
            'add_ons.*.price' => 'nullable|string',
            'add_ons.*.period' => 'nullable|string',
            'terms_lightweight' => 'nullable|string',
        ]);

        // Build content from structured sections if content empty (backward compat)
        $content = $request->content;
        if (empty(trim((string) $content)) && ($request->scope_of_work || $request->introduction_message || $request->problem_solution)) {
            $content = $this->buildContentFromSections($request);
        }
        if (empty(trim((string) $content))) {
            $content = $request->title . "\n\n[No content provided]";
        }

        // Check plan restrictions
        $limits = $business->getPlanLimits();
        $plan = $business->plan ?? 'free';
        $planName = Business::planDisplayName($plan);
        $resourceKey = $request->type === 'proposal' ? 'proposals' : 'contracts';
        $resourceLimit = $limits[$resourceKey] ?? $limits['contracts'] ?? 0;
        $resourceUsage = $business->contracts()
            ->where('type', $request->type)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        if ($resourceLimit !== -1 && $resourceUsage >= $resourceLimit) {
            $upgradeData = \App\Services\UpgradeRecommendationService::generateUpgradeMessage(
                $business, 
                $resourceKey,
                ['usage' => $resourceUsage, 'limit' => $resourceLimit]
            );
            
            return response()->json($upgradeData, 403);
        }

        // Auto-apply business signature if available and not explicitly provided
        $businessSigName = $request->business_signature_name;
        $businessSigImage = $request->business_signature_image;
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

        $contract = Contract::create([
            'business_id' => $business->id,
            'client_id' => $request->client_id,
            'contract_number' => $business->generateContractNumber($request->type),
            'title' => $request->title,
            'type' => $request->type,
            'industry_type' => $request->industry_type,
            'content' => $content,
            'status' => 'draft',
            'value' => $request->value,
            'pricing_type' => $request->pricing_type,
            'created_date' => $request->created_date,
            'expiry_date' => $request->expiry_date,
            'scope_of_work' => $request->scope_of_work,
            'milestones' => $request->milestones,
            'payment_terms' => $request->payment_terms,
            'ownership_rights' => $request->ownership_rights,
            'confidentiality_enabled' => (bool) $request->confidentiality_enabled,
            'termination_notice_days' => $request->termination_notice_days,
            'termination_payment_note' => $request->termination_payment_note,
            'introduction_message' => $request->introduction_message,
            'problem_solution' => $request->problem_solution,
            'packages' => $request->packages,
            'add_ons' => $request->add_ons,
            'terms_lightweight' => $request->terms_lightweight,
            'business_signature_name' => $businessSigName,
            'business_signature_image' => $businessSigImage,
            'business_signed_at' => $businessSignedAt,
            'signing_token' => Str::random(64),
        ]);

        app(PdfService::class)->generateContractPdf($contract);
        ActivityLog::log('contract.created', "Contract {$contract->contract_number} created", $contract);

        return response()->json([
            'message' => ucfirst($request->type) . ' created successfully',
            'contract' => $contract->load('client'),
        ], 201);
    }

    public function show($business, $contract)
    {
        $businessObj = $business instanceof Business ? $business : Business::findOrFail($business);
        $contractObj = $contract instanceof Contract ? $contract : Contract::findOrFail($contract);

        if ($contractObj->business_id !== $businessObj->id) {
            return response()->json(['message' => 'Contract not found'], 404);
        }
        $contractObj->load('client');
        $linkedInvoice = $contractObj->invoices()->latest()->first();
        $contractObj->makeVisible('signing_token');
        $payload = ['contract' => $contractObj];
        if ($linkedInvoice) {
            $payload['linked_invoice'] = [
                'id' => $linkedInvoice->id,
                'invoice_number' => $linkedInvoice->invoice_number,
                'status' => $linkedInvoice->status,
            ];
        } else {
            $payload['linked_invoice'] = null;
        }
        return response()->json($payload);
    }

    public function update(Request $request, $business, $contract)
    {
        $businessObj = $business instanceof Business ? $business : Business::findOrFail($business);
        $contractObj = $contract instanceof Contract ? $contract : Contract::findOrFail($contract);

        if ($contractObj->business_id !== $businessObj->id) {
            return response()->json(['message' => 'Contract not found'], 404);
        }
        if (!in_array($contractObj->status, ['draft'])) {
            return response()->json(['message' => 'Only draft documents can be edited'], 422);
        }

        $request->validate([
            'title' => 'sometimes|string|max:255',
            'content' => 'nullable|string',
            'value' => 'sometimes|numeric|min:0',
            'created_date' => 'sometimes|date',
            'expiry_date' => 'sometimes|date',
            'industry_type' => 'nullable|string|max:80',
            'pricing_type' => 'nullable|string|in:fixed,hourly,milestone_based',
            'scope_of_work' => 'nullable|array',
            'milestones' => 'nullable|array',
            'payment_terms' => 'nullable|array',
            'ownership_rights' => 'nullable|array',
            'confidentiality_enabled' => 'nullable|boolean',
            'termination_notice_days' => 'nullable|integer|min:0|max:365',
            'termination_payment_note' => 'nullable|string|max:500',
            'introduction_message' => 'nullable|string',
            'problem_solution' => 'nullable|array',
            'packages' => 'nullable|array',
            'add_ons' => 'nullable|array',
            'terms_lightweight' => 'nullable|string',
        ]);

        $payload = $request->only([
            'title', 'content', 'value', 'created_date', 'expiry_date', 'industry_type', 'pricing_type',
            'scope_of_work', 'milestones', 'payment_terms', 'ownership_rights',
            'confidentiality_enabled', 'termination_notice_days', 'termination_payment_note',
            'introduction_message', 'problem_solution', 'packages', 'add_ons', 'terms_lightweight',
        ]);
        if (array_key_exists('confidentiality_enabled', $payload) && $payload['confidentiality_enabled'] === null) {
            $payload['confidentiality_enabled'] = false;
        }
        if (empty(trim((string) ($payload['content'] ?? ''))) && ($request->scope_of_work || $request->introduction_message || $request->problem_solution)) {
            $payload['content'] = $this->buildContentFromSections($request);
        }
        $contractObj->update($payload);

    // Auto-apply business signature if not already set
    if (empty($contractObj->business_signature_name) && !empty($businessObj->signature_name)) {
        $contractObj->update([
            'business_signature_name' => $businessObj->signature_name,
            'business_signature_image' => $businessObj->signature_image,
            'business_signed_at' => now(),
        ]);
    }

    app(PdfService::class)->generateContractPdf($contractObj);

        return response()->json(['message' => 'Document updated', 'contract' => $contractObj]);
    }

    private function buildContentFromSections(Request $request): string
    {
        $parts = [];
        if (!empty($request->introduction_message)) {
            $parts[] = trim($request->introduction_message);
        }
        $ps = $request->problem_solution;
        if (is_array($ps) && (!empty($ps['client_problem']) || !empty($ps['your_solution']))) {
            $parts[] = "PROBLEM & SOLUTION\n";
            if (!empty($ps['client_problem'])) {
                $parts[] = "Client challenge:\n" . trim($ps['client_problem']);
            }
            if (!empty($ps['your_solution'])) {
                $parts[] = "\nOur solution:\n" . trim($ps['your_solution']);
            }
        }
        $sow = $request->scope_of_work;
        if (is_array($sow)) {
            $parts[] = "\nSCOPE OF WORK\n";
            if (!empty($sow['service_description'])) {
                $parts[] = trim($sow['service_description']);
            }
            if (!empty($sow['deliverables'])) {
                $parts[] = "\nDeliverables:\n" . implode("\n", array_map(fn ($d) => '• ' . $d, (array) $sow['deliverables']));
            }
            if (!empty($sow['exclusions'])) {
                $parts[] = "\nExclusions:\n" . trim($sow['exclusions']);
            }
        }
        $milestones = $request->milestones;
        if (is_array($milestones) && count($milestones) > 0) {
            $parts[] = "\nTIMELINE / MILESTONES\n";
            foreach ($milestones as $m) {
                $m = (array) $m;
                $line = '• ' . ($m['name'] ?? 'Milestone');
                if (!empty($m['description'])) {
                    $line .= ' – ' . $m['description'];
                }
                if (!empty($m['due_date']) || !empty($m['amount'])) {
                    $line .= ' (' . trim(($m['due_date'] ?? '') . ' ' . ($m['amount'] ?? '')) . ')';
                }
                $parts[] = $line;
            }
        }
        $packages = $request->packages;
        if (is_array($packages) && count($packages) > 0) {
            $parts[] = "\nPACKAGES / PRICING\n";
            foreach ($packages as $p) {
                $p = (array) $p;
                $line = '• ' . ($p['name'] ?? 'Package');
                if (!empty($p['price'])) {
                    $line .= ' – ' . $p['price'];
                }
                if (!empty($p['description'])) {
                    $line .= "\n  " . $p['description'];
                }
                $parts[] = $line;
            }
        }
        $paymentTerms = $request->payment_terms;
        if (is_array($paymentTerms) && (!empty($paymentTerms['schedule_text']) || !empty($paymentTerms['late_payment_clause']))) {
            $parts[] = "\nPAYMENT TERMS\n";
            if (!empty($paymentTerms['schedule_text'])) {
                $parts[] = trim($paymentTerms['schedule_text']);
            }
            if (!empty($paymentTerms['late_payment_clause'])) {
                $parts[] = "Late payment: " . trim($paymentTerms['late_payment_clause']);
            }
        }
        if (!empty($request->terms_lightweight)) {
            $parts[] = "\nTERMS & NEXT STEPS\n" . trim($request->terms_lightweight);
        }
        return implode("\n\n", $parts) ?: $request->title . "\n\n[Content from sections]";
    }

    public function destroy($business, $contract)
    {
        $businessObj = $business instanceof Business ? $business : Business::findOrFail($business);
        $contractObj = $contract instanceof Contract ? $contract : Contract::findOrFail($contract);

        if ($contractObj->business_id !== $businessObj->id) {
            return response()->json(['message' => 'Contract not found'], 404);
        }
        if (!in_array($contractObj->status, ['draft', 'declined'])) {
            return response()->json(['message' => 'Only draft or declined documents can be deleted'], 422);
        }

        $contractObj->delete();
        ActivityLog::log('contract.deleted', "Contract {$contractObj->contract_number} deleted");

        return response()->json(['message' => 'Document deleted']);
    }

    public function send($business, $contract)
    {
        $businessObj = $business instanceof Business ? $business : Business::findOrFail($business);
        $contractObj = $contract instanceof Contract ? $contract : Contract::findOrFail($contract);

        if ($contractObj->business_id !== $businessObj->id) {
            return response()->json(['message' => 'Contract not found'], 404);
        }
        $contractObj->load(['business', 'client']);

        try {
            Mail::to($contractObj->client->email)->send(new ContractMail($contractObj, 'send'));
        } catch (\Exception $e) {
            \Log::error('Failed to send contract email: ' . $e->getMessage(), ['contract_id' => $contractObj->id]);
            return response()->json(['message' => 'Unable to send contract email. Please try again.'], 502);
        }

        $contractObj->update(['status' => 'sent', 'sent_at' => now()]);
        ActivityLog::log('contract.sent', "Contract {$contractObj->contract_number} sent", $contractObj);

        // Build WhatsApp share link for the signing URL
        $signingUrl = config('app.frontend_url') . '/sign/contract/' . $contractObj->signing_token;
        $whatsappMessage = urlencode(
            "Hi {$contractObj->client->name},\n\n"
            . "{$businessObj->name} has sent you a " . ($contractObj->type === 'proposal' ? 'proposal' : 'contract') . " to review and sign.\n\n"
            . "📄 {$contractObj->title}\n"
            . "💰 GH₵ " . number_format($contractObj->value, 2) . "\n\n"
            . "Review & Sign: {$signingUrl}\n\n"
            . "Powered by EsperWorks"
        );
        $whatsappUrl = "https://wa.me/?text={$whatsappMessage}";

        return response()->json([
            'message' => 'Document sent to client',
            'signing_url' => $signingUrl,
            'whatsapp_url' => $whatsappUrl,
        ]);
    }

    public function sendReminder($business, $contract)
    {
        $businessObj = $business instanceof Business ? $business : Business::findOrFail($business);
        $contractObj = $contract instanceof Contract ? $contract : Contract::findOrFail($contract);

        if ($contractObj->business_id !== $businessObj->id) {
            return response()->json(['message' => 'Contract not found'], 404);
        }
        $contractObj->load(['business', 'client']);

        try {
            Mail::to($contractObj->client->email)->send(new ContractMail($contractObj, 'reminder'));
        } catch (\Exception $e) {
            \Log::error('Failed to send contract reminder email: ' . $e->getMessage(), ['contract_id' => $contractObj->id]);
            return response()->json(['message' => 'Unable to send reminder email. Please try again.'], 502);
        }
        ActivityLog::log('contract.reminder', "Reminder sent for {$contractObj->contract_number}", $contractObj);

        return response()->json(['message' => 'Reminder sent']);
    }

    public function resendSignature($business, $contract)
    {
        $businessObj = $business instanceof Business ? $business : Business::findOrFail($business);
        $contractObj = $contract instanceof Contract ? $contract : Contract::findOrFail($contract);

        if ($contractObj->business_id !== $businessObj->id) {
            return response()->json(['message' => 'Contract not found'], 404);
        }
        $contractObj->load(['business', 'client']);

        if (!$contractObj->client || empty($contractObj->client->email)) {
            return response()->json(['message' => 'Client email is required to resend signature request'], 422);
        }

        // Regenerate signing token if expired
        if ($contractObj->isTokenExpired()) {
            $contractObj->update([
                'signing_token' => \Illuminate\Support\Str::random(64),
                'token_expires_at' => now()->addDays(30),
            ]);
        }

        try {
            Mail::to($contractObj->client->email)->send(new ContractMail($contractObj, 'signature_request'));
        } catch (\Exception $e) {
            \Log::error('Failed to send signature request email: ' . $e->getMessage(), ['contract_id' => $contractObj->id]);
            return response()->json(['message' => 'Failed to send signature request. Please try again.'], 500);
        }

        ActivityLog::log('contract.signature_resent', "Signature request resent for {$contractObj->contract_number}", $contractObj);

        return response()->json([
            'message' => 'Signature request sent successfully',
            'client_email' => $contractObj->client->email,
        ]);
    }

    /**
     * Request client signature - sends a signature request notification to the client
     */
    public function requestSignature($business, $contract)
    {
        $businessObj = $business instanceof Business ? $business : Business::findOrFail($business);
        $contractObj = $contract instanceof Contract ? $contract : Contract::findOrFail($contract);

        if ($contractObj->business_id !== $businessObj->id) {
            return response()->json(['message' => 'Contract not found'], 404);
        }

        // Contract must be sent and not yet signed by client
        if (!in_array($contractObj->status, ['sent', 'viewed'])) {
            return response()->json(['message' => 'Contract must be sent before requesting signature'], 422);
        }

        if ($contractObj->client_signed_at) {
            return response()->json(['message' => 'Client has already signed this document'], 422);
        }

        $contractObj->load(['business', 'client']);

        // Create notification for client
        \App\Models\Notification::create([
            'user_id' => $contractObj->client->user_id,
            'type' => 'signature_request',
            'title' => 'Signature Requested',
            'message' => "{$contractObj->business->name} is requesting your signature on {$contractObj->title}",
            'data' => [
                'contract_id' => $contractObj->id,
                'contract_number' => $contractObj->contract_number,
                'business_name' => $contractObj->business->name,
                'type' => $contractObj->type,
            ],
            'link' => "/client/dashboard/contracts/{$contractObj->id}",
        ]);

        // Send email notification
        try {
            Mail::to($contractObj->client->email)->send(new ContractMail($contractObj, 'signature_request'));
        } catch (\Exception $e) {
            \Log::warning('Failed to send signature request email: ' . $e->getMessage());
        }

        ActivityLog::log('contract.signature_requested', "Signature requested for {$contractObj->contract_number}", $contractObj);

        return response()->json(['message' => 'Signature request sent to client']);
    }

    public function sign(Request $request, $contract)
    {
        if (!$contract instanceof Contract) {
            $contract = Contract::whereKey($contract)->first();
            if (!$contract) {
                return response()->json(['message' => 'Contract not found'], 404);
            }
        }
        $type = $request->input('type') ?? $request->input('signer_type');
        $request->merge(['type' => $type]);
        $request->validate([
            'type' => 'required|in:business,client',
            'signature_name' => 'required|string|max:255',
            'signature_image' => 'nullable|string',
            'signature_type' => 'nullable|string',
            'token' => 'nullable|string',
        ]);

        if ($request->type === 'client') {
            // Client signatures require a valid signing token OR authenticated client user
            if (!$request->token || $request->token !== $contract->signing_token) {
                $user = auth('sanctum')->user();
                if (!$user) {
                    return response()->json(['message' => 'Invalid or missing signing token'], 403);
                }
                $clientIds = $user->clientProfiles()->pluck('id');
                if (!$clientIds->contains($contract->client_id)) {
                    return response()->json(['message' => 'You are not authorized to sign this document'], 403);
                }
            }
            // Client cannot sign before business has signed
            if (!$contract->business_signature_name) {
                return response()->json(['message' => 'This document must be signed by the business first'], 422);
            }
            // If client already responded with reject, do not allow sign
            if ($contract->hasClientResponded() && !$contract->hasClientAccepted()) {
                return response()->json(['message' => 'You have declined this document.'], 422);
            }
        } else {
            // Business signatures require authentication + ownership
            $user = auth('sanctum')->user();
            if (!$user) {
                return response()->json(['message' => 'Authentication required'], 401);
            }
            $contract->load('business');
            if ($user->id !== $contract->business->user_id && !$user->isAdmin()) {
                return response()->json(['message' => 'You are not authorized to sign this document'], 403);
            }
        }

        $field = $request->type;

        $contract->update([
            "{$field}_signature_name" => $request->signature_name,
            "{$field}_signature_image" => $request->signature_image,
            "{$field}_signed_at" => now(),
        ]);

        // If both signed, mark as signed and notify
        if ($contract->fresh()->isFullySigned()) {
            $contract->update(['status' => 'signed']);
            $contract->load(['business', 'client']);
            try {
                Mail::to($contract->client->email)->send(new ContractMail($contract, 'signed'));
                Mail::to($contract->business->email)->send(new ContractMail($contract, 'signed'));
            } catch (\Exception $e) {
                \Log::warning('Failed to send contract signed emails: ' . $e->getMessage());
            }
            try {
                app(\App\Services\ContractToInvoiceService::class)->createDraftInvoiceIfEligible($contract->fresh());
            } catch (\Throwable $e) {
                \Log::warning('Failed to create draft invoice from signed contract: ' . $e->getMessage());
            }
        } elseif ($request->type === 'business' && !$contract->client_signature_name) {
            // Business signed first, send to client for their signature
            $contract->load(['business', 'client']);
            try {
                Mail::to($contract->client->email)->send(new ContractMail($contract, 'signature_request'));
                ActivityLog::log('contract.sent_to_client', "Contract {$contract->contract_number} sent to client for signature after business signature", $contract);
            } catch (\Exception $e) {
                \Log::warning('Failed to send contract to client after business signature: ' . $e->getMessage());
            }
        }

        app(PdfService::class)->generateContractPdf($contract);
        ActivityLog::log('contract.signed', "{$request->type} signed {$contract->contract_number}", $contract);

        return response()->json(['message' => ucfirst($request->type) . ' signature applied', 'contract' => $contract]);
    }

    public function sendToClient($business, $contract)
    {
        $businessObj = $business instanceof Business ? $business : Business::findOrFail($business);
        $contractObj = $contract instanceof Contract ? $contract : Contract::findOrFail($contract);

        if ($contractObj->business_id !== $businessObj->id) {
            return response()->json(['message' => 'Contract not found'], 404);
        }

        $contractObj->load(['business', 'client']);

        // Only allow sending to client if business is signed but client is not
        if (!$contractObj->business_signature_name || $contractObj->client_signature_name) {
            return response()->json(['message' => 'Contract cannot be sent to client. Business must sign first and client must not have already signed.'], 422);
        }

        try {
            Mail::to($contractObj->client->email)->send(new ContractMail($contractObj, 'signature_request'));
            ActivityLog::log('contract.sent_to_client', "Contract {$contractObj->contract_number} manually sent to client for signature", $contractObj);
            
            return response()->json(['message' => 'Contract sent to client for signature']);
        } catch (\Exception $e) {
            \Log::error('Failed to send contract to client: ' . $e->getMessage(), ['contract_id' => $contractObj->id]);
            return response()->json(['message' => 'Unable to send contract to client. Please try again.'], 502);
        }
    }

    public function downloadPdf($business, $contract)
    {
        $businessObj = $business instanceof Business ? $business : Business::findOrFail($business);
        $contractObj = $contract instanceof Contract ? $contract : Contract::findOrFail($contract);

        if ($contractObj->business_id !== $businessObj->id) {
            return response()->json(['message' => 'Contract not found'], 404);
        }
        return app(PdfService::class)->streamContractPdf($contractObj);
    }

    public function viewByToken(string $token)
    {
        $contract = Contract::where('signing_token', $token)->with(['business', 'client'])->firstOrFail();

        // If the token has expired, block viewing via public link
        if (method_exists($contract, 'isTokenExpired') && $contract->isTokenExpired()) {
            return response()->json([
                'message' => 'This document link has expired. Please contact the business for a new link.',
            ], 410);
        }

        if (!$contract->viewed_at) {
            $contract->update(['viewed_at' => now(), 'status' => $contract->status === 'sent' ? 'viewed' : $contract->status]);
        }

        return response()->json(['contract' => $contract]);
    }

    /** Public PDF download by signing token (for client/public link). Works for signed or unsigned documents. */
    public function downloadPdfByToken(string $token)
    {
        $contract = Contract::where('signing_token', $token)->with(['business', 'client'])->firstOrFail();

        if (method_exists($contract, 'isTokenExpired') && $contract->isTokenExpired()) {
            return response()->json([
                'message' => 'This document link has expired. Please contact the business for a new link.',
            ], 410);
        }
        return app(PdfService::class)->streamContractPdf($contract);
    }

    public function signByToken(Request $request, string $token)
    {
        $contract = Contract::where('signing_token', $token)->firstOrFail();

        // Reject expired links the same way as viewByToken
        if (method_exists($contract, 'isTokenExpired') && $contract->isTokenExpired()) {
            return response()->json([
                'message' => 'This document link has expired. Please contact the business for a new link.',
            ], 410);
        }
        
        $request->validate([
            'signature_name' => 'required|string|max:255',
            'signature_image' => 'required|string',
            'signature_method' => 'required|in:draw,type,upload',
            'signer_type' => 'required|in:client',
        ]);

        // Only allow client signatures via token
        if ($request->signer_type !== 'client') {
            return response()->json(['message' => 'Invalid signer type for token-based signing'], 403);
        }

        // Prevent duplicate client signing via token
        if ($contract->client_signed_at) {
            return response()->json([
                'message' => 'This document has already been signed by the client.',
            ], 422);
        }

        // Update contract with client signature
        $updateData = [
            'client_signature_name' => $request->signature_name,
            'client_signature_image' => $request->signature_image,
            'client_signed_at' => now(),
        ];

        // Only mark as fully signed if both parties have signed
        if ($contract->business_signed_at) {
            $updateData['status'] = 'signed';
        }

        $contract->update($updateData);
        $contract->refresh();

        app(PdfService::class)->generateContractPdf($contract);

        ActivityLog::create([
            'business_id' => $contract->business_id,
            'user_id' => null,
            'action' => 'contract_signed',
            'description' => "Contract {$contract->contract_number} signed by client via token",
            'details' => json_encode(['contract_id' => $contract->id, 'signer' => $request->signature_name]),
        ]);

        if ($contract->status === 'signed') {
            try {
                app(\App\Services\ContractToInvoiceService::class)->createDraftInvoiceIfEligible($contract);
            } catch (\Throwable $e) {
                \Log::warning('Failed to create draft invoice from signed contract (token): ' . $e->getMessage());
            }
        }

        return response()->json(['message' => 'Contract signed successfully']);
    }
}
