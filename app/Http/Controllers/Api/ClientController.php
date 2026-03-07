<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Business;
use App\Models\User;
use App\Models\Invoice;
use App\Models\Contract;
use App\Models\ActivityLog;
use App\Mail\ClientInviteMail;
use App\Services\PaystackService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ClientController extends Controller
{
    public function index(Request $request, Business $business)
    {
        $query = $business->clients();

        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                    ->orWhere('email', 'like', "%{$request->search}%")
                    ->orWhere('city', 'like', "%{$request->search}%");
            });
        }

        $clients = $query->withCount('invoices')
            ->withSum(['invoices as total_revenue' => fn($q) => $q->where('status', 'paid')], 'total')
            ->withSum(['invoices as outstanding' => fn($q) => $q->whereIn('status', ['sent', 'viewed', 'overdue'])], 'total')
            ->latest()
            ->paginate($request->per_page ?? 20);

        return response()->json($clients);
    }

    public function store(Request $request, Business $business)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'company' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        // Check plan restrictions
        if (!$business->canAddClient()) {
            $limits = $business->getPlanLimits();
            $usage = $business->clients()->count();
            
            $upgradeData = \App\Services\UpgradeRecommendationService::generateUpgradeMessage(
                $business, 
                'clients',
                ['usage' => $usage, 'limit' => $limits['clients'] ?? 10]
            );
            
            return response()->json($upgradeData, 403);
        }

        $exists = $business->clients()->where('email', $request->email)->exists();
        if ($exists) {
            return response()->json(['message' => 'A client with this email already exists'], 422);
        }

        $client = Client::create([
            'business_id' => $business->id,
            ...$request->only(['name', 'email', 'phone', 'address', 'city', 'country', 'company', 'notes']),
        ]);

        ActivityLog::log('client.created', "Client {$client->name} added", $client);

        return response()->json(['message' => 'Client added', 'client' => $client], 201);
    }

    public function show(Business $business, Client $client)
    {
        // Ensure we compare integers to avoid type-mismatch issues between string and int IDs
        if ((int) $client->business_id !== (int) $business->id) {
            return response()->json(['message' => 'Client not found'], 404);
        }
        $client->loadCount('invoices');
        $client->load(['invoices' => fn($q) => $q->latest()->take(10), 'contracts' => fn($q) => $q->latest()->take(5)]);

        return response()->json([
            'client' => $client,
            'stats' => [
                'total_revenue' => $client->totalRevenue(),
                'outstanding' => $client->outstandingAmount(),
                'total_invoices' => $client->invoices_count,
            ],
        ]);
    }

    public function update(Request $request, Business $business, Client $client)
    {
        if ((int) $client->business_id !== (int) $business->id) {
            return response()->json(['message' => 'Client not found'], 404);
        }
        $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'company' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'status' => 'sometimes|in:active,inactive',
        ]);

        $client->update($request->only(['name', 'email', 'phone', 'address', 'city', 'company', 'notes', 'status']));

        return response()->json(['message' => 'Client updated', 'client' => $client]);
    }

    public function destroy(Business $business, Client $client)
    {
        if ((int) $client->business_id !== (int) $business->id) {
            return response()->json(['message' => 'Client not found'], 404);
        }
        if ($client->invoices()->whereNotIn('status', ['draft', 'cancelled'])->exists()) {
            return response()->json(['message' => 'Cannot delete client with active invoices'], 422);
        }

        $client->delete();
        ActivityLog::log('client.deleted', "Client {$client->name} deleted");

        return response()->json(['message' => 'Client deleted']);
    }

    public function invite(Request $request, Business $business, Client $client)
    {
        if ((int) $client->business_id !== (int) $business->id) {
            return response()->json(['message' => 'Client not found'], 404);
        }
        $temporaryPassword = 'Esp3r@' . Str::random(8);

        // Create or find the user account for this client
        $user = User::where('email', $client->email)->first();

        if (!$user) {
            $user = User::create([
                'name' => $client->name,
                'email' => $client->email,
                'password' => $temporaryPassword,
                'role' => 'client',
                'status' => 'active',
                'must_change_password' => true,
            ]);
        } else {
            $user->update([
                'password' => $temporaryPassword,
                'must_change_password' => true,
            ]);
        }

        $client->update([
            'user_id' => $user->id,
            'portal_invited' => true,
            'portal_invited_at' => now(),
        ]);

        $frontend = rtrim(config('app.frontend_url'), '/');
        $loginUrl = $frontend ? $frontend . '/client/login' : '/client/login';

        // Send invitation email, but don't fail if email configuration is not set up
        try {
            Mail::to($client->email)->send(new ClientInviteMail($client, $business, $temporaryPassword, $loginUrl));
        } catch (\Exception $e) {
            // Log the email error but don't fail the invitation
            \Log::warning('Failed to send client invitation email: ' . $e->getMessage());
        }

        ActivityLog::log('client.invited', "Client {$client->name} invited to portal", $client);

        $response = ['message' => 'Invitation sent to ' . $client->email];
        if (app()->environment('local', 'testing')) {
            $response['temporary_password'] = $temporaryPassword;
        }
        return response()->json($response);
    }

    // Client portal endpoints
    public function portalInvoices(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Authentication required'], 401);
        }
        $clientIds = $user->clientProfiles()->pluck('id');

        if ($clientIds->isEmpty()) {
            return response()->json(['data' => [], 'meta' => ['total' => 0]]);
        }

        $query = \App\Models\Invoice::whereIn('client_id', $clientIds)->with('business:id,name,logo')->with('items');

        if ($request->status && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        return response()->json($query->latest()->paginate(15));
    }

    public function portalContracts(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Authentication required'], 401);
        }
        $clientIds = $user->clientProfiles()->pluck('id');

        if ($clientIds->isEmpty()) {
            return response()->json(['data' => [], 'meta' => ['total' => 0]]);
        }

        $query = \App\Models\Contract::whereIn('client_id', $clientIds)->with('business:id,name,logo');

        if ($request->filled('type') && in_array($request->type, ['contract', 'proposal'], true)) {
            $query->where('type', $request->type);
        }
        if ($request->status && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        return response()->json($query->latest()->paginate(15));
    }

    public function showInvoice(Request $request, \App\Models\Invoice $invoice)
    {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json(['message' => 'Authentication required'], 401);
            }

            // Get client profiles with error handling
            $clientProfiles = $user->clientProfiles;
            if (!$clientProfiles) {
                return response()->json(['message' => 'Client profiles not found'], 404);
            }

            $clientIds = $clientProfiles->pluck('id');
            if ($clientIds->isEmpty()) {
                return response()->json(['message' => 'No client profiles found for this user'], 404);
            }

            // Ensure the invoice belongs to one of this user's client profiles
            if (!$clientIds->contains($invoice->client_id)) {
                return response()->json(['message' => 'Invoice not found or access denied'], 404);
            }

            $invoice->load(['business:id,name,logo,email,phone', 'items']);

            // Track invoice viewing (only for dashboard views, not token views)
            if (!$invoice->viewed_at) {
                // Only update status to 'viewed' if not already paid
                $newStatus = $invoice->status === 'sent' ? 'viewed' : $invoice->status;
                if ($invoice->status !== 'paid') {
                    $invoice->update(['viewed_at' => now(), 'status' => $newStatus]);
                } else {
                    // For paid invoices, just update viewed_at but keep paid status
                    $invoice->update(['viewed_at' => now()]);
                }

                // Log activity for business
                ActivityLog::create([
                    'business_id' => $invoice->business_id,
                    'user_id' => $user->id,
                    'action' => 'invoice.viewed',
                    'description' => "Invoice {$invoice->invoice_number} viewed by client in dashboard",
                    'data' => ['invoice_id' => $invoice->id, 'client_user_id' => $user->id],
                ]);

                // Create notification for business user (owner)
                $ownerId = $invoice->business->user_id;
                if ($ownerId) {
                    \App\Models\Notification::create([
                        'user_id' => $ownerId,
                        'business_id' => $invoice->business_id,
                        'type' => 'invoice_viewed',
                        'title' => 'Invoice Viewed',
                        'message' => "Your client {$user->name} has viewed invoice #{$invoice->invoice_number}",
                        'data' => [
                            'invoice_id' => $invoice->id,
                            'client_name' => $user->name,
                            'invoice_number' => $invoice->invoice_number,
                        ],
                        'link' => "/dashboard/invoices/{$invoice->id}",
                    ]);
                }
            }

            $invoice->makeVisible('signing_token');
            return response()->json(['data' => $invoice]);
        } catch (\Exception $e) {
            \Log::error('Client showInvoice error: ' . $e->getMessage(), [
                'invoice_id' => $invoice->id ?? null,
                'user_id' => $request->user()?->id,
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['message' => 'An error occurred. Please try again.'], 500);
        }
    }

    public function showContract(Request $request, Contract $contract)
    {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json(['message' => 'Authentication required'], 401);
            }
            // Authorize: contract must belong to a client profile linked to this user (type-safe compare)
            $clientIds = $user->clientProfiles()->pluck('id')->map(fn ($id) => (int) $id)->values();
            $contractClientId = (int) $contract->client_id;
            $belongsToUser = $clientIds->contains($contractClientId);
            if (!$belongsToUser) {
                $contract->load('client:id,user_id');
                if ($contract->client && (int) $contract->client->user_id === (int) $user->id) {
                    $belongsToUser = true;
                }
            }
            if (!$belongsToUser) {
                return response()->json(['message' => 'Contract not found or access denied'], 404);
            }
            $contract->load(['business:id,name,logo,email,phone']);
            if (!$contract->viewed_at) {
                $contract->update(['viewed_at' => now(), 'status' => $contract->status === 'sent' ? 'viewed' : $contract->status]);
            }
            return response()->json(['data' => $contract]);
        } catch (\Exception $e) {
            \Log::error('Client showContract error: ' . $e->getMessage(), [
                'contract_id' => $contract->id ?? null,
                'user_id' => $request->user()?->id,
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['message' => 'An error occurred. Please try again.'], 500);
        }
    }

    public function acceptContract(Request $request, Contract $contract)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Authentication required'], 401);
        }
        $clientIds = $user->clientProfiles()->pluck('id')->map(fn ($id) => (int) $id)->values();
        $belongsToUser = $clientIds->contains((int) $contract->client_id);
        if (!$belongsToUser) {
            $contract->load('client:id,user_id');
            $belongsToUser = $contract->client && (int) $contract->client->user_id === (int) $user->id;
        }
        if (!$belongsToUser) {
            return response()->json(['message' => 'Contract not found or access denied'], 404);
        }
        if ($contract->hasClientResponded()) {
            return response()->json(['message' => 'You have already responded to this document.'], 422);
        }
        if (!$contract->canClientRespond()) {
            return response()->json(['message' => 'This document is no longer available for response.'], 422);
        }
        $contract->recordAccept();
        return response()->json(['message' => 'Accepted. You can now sign the document.', 'contract' => $contract->fresh()]);
    }

    public function rejectContract(Request $request, Contract $contract)
    {
        $request->validate(['reason' => 'nullable|string|max:2000']);
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Authentication required'], 401);
        }
        $clientIds = $user->clientProfiles()->pluck('id')->map(fn ($id) => (int) $id)->values();
        $belongsToUser = $clientIds->contains((int) $contract->client_id);
        if (!$belongsToUser) {
            $contract->load('client:id,user_id');
            $belongsToUser = $contract->client && (int) $contract->client->user_id === (int) $user->id;
        }
        if (!$belongsToUser) {
            return response()->json(['message' => 'Contract not found or access denied'], 404);
        }
        if ($contract->hasClientResponded()) {
            return response()->json(['message' => 'You have already responded to this document.'], 422);
        }
        if (!$contract->canClientRespond()) {
            return response()->json(['message' => 'This document is no longer available for response.'], 422);
        }
        $contract->rejectContract($request->input('reason', 'No reason provided'));
        return response()->json(['message' => 'You have declined this document.', 'contract' => $contract->fresh()]);
    }

    /**
     * Client submits a change request (comment/message) for an invoice.
     * Business sees it in activity and notifications; only business can edit the invoice.
     */
    public function requestInvoiceChange(Request $request, \App\Models\Invoice $invoice)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Authentication required'], 401);
        }

        $invoice->load('client');
        $clientIds = $user->clientProfiles()->pluck('id');
        if ($clientIds->isEmpty() && $invoice->client && (int) $invoice->client->user_id === (int) $user->id) {
            $clientIds = collect([$invoice->client_id]);
        }
        if (!$clientIds->contains($invoice->client_id)) {
            return response()->json(['message' => 'Invoice not found or access denied'], 404);
        }

        $request->validate([
            'message' => 'required|string|max:2000',
        ]);

        $invoice->load('business');
        $clientName = $invoice->client ? $invoice->client->name : 'Client';

        ActivityLog::log(
            'invoice.change_request',
            "{$clientName} requested changes on invoice {$invoice->invoice_number}",
            $invoice,
            ['message' => $request->input('message'), 'client_user_id' => $user->id]
        );

        $owner = $invoice->business->owner;
        if ($owner) {
            \App\Services\AdminNotificationService::create(
                'invoice.change_request',
                'Invoice change requested',
                "{$clientName} requested changes on invoice #{$invoice->invoice_number}. Review in Invoices.",
                [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'message' => $request->input('message'),
                    'business_id' => $invoice->business_id,
                ],
                $owner->id,
                $invoice->business_id
            );
        }

        return response()->json([
            'message' => 'Your change request has been sent. The business will review it and may update the invoice.',
        ]);
    }

    public function downloadInvoicePdf(Request $request, \App\Models\Invoice $invoice)
    {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json(['message' => 'Authentication required'], 401);
            }

            $clientProfiles = $user->clientProfiles;
            if (!$clientProfiles) {
                return response()->json(['message' => 'Client profiles not found'], 404);
            }

            $clientIds = $clientProfiles->pluck('id');
            if ($clientIds->isEmpty()) {
                return response()->json(['message' => 'No client profiles found for this user'], 404);
            }

            if (!$clientIds->contains($invoice->client_id)) {
                return response()->json(['message' => 'Invoice not found or access denied'], 404);
            }

            $invoice->load(['business:id,name,logo', 'items']);

            // Check if client has permission to download this invoice
            if (!$invoice->client_signature_required || $invoice->client_signed_at) {
                // Allow download if signature is not required or already signed
            } else {
                // Require signature before download
                return response()->json(['message' => 'Please sign the invoice before downloading'], 403);
            }

            // Stream PDF
            $pdfService = new \App\Services\PdfService();
            return $pdfService->streamInvoicePdf($invoice);
        } catch (\Exception $e) {
            \Log::error('Client downloadInvoicePdf error: ' . $e->getMessage(), [
                'invoice_id' => $invoice->id ?? null,
                'user_id' => $request->user()?->id,
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['message' => 'Download failed. Please try again.'], 500);
        }
    }

    public function downloadContractPdf(Request $request, Contract $contract)
    {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json(['message' => 'Authentication required'], 401);
            }
            $clientIds = $user->clientProfiles()->pluck('id')->map(fn ($id) => (int) $id)->values();
            $belongsToUser = $clientIds->contains((int) $contract->client_id);
            if (!$belongsToUser) {
                $contract->load('client:id,user_id');
                $belongsToUser = $contract->client && (int) $contract->client->user_id === (int) $user->id;
            }
            if (!$belongsToUser) {
                return response()->json(['message' => 'Contract not found or access denied'], 404);
            }
            $contract->load(['business:id,name,logo']);
            // Allow download whether signed or not (client can download/print at any time)
            $pdfService = new \App\Services\PdfService();
            return $pdfService->streamContractPdf($contract);
        } catch (\Exception $e) {
            \Log::error('Client downloadContractPdf error: ' . $e->getMessage(), [
                'contract_id' => $contract->id ?? null,
                'user_id' => $request->user()?->id,
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['message' => 'Download failed. Please try again.'], 500);
        }
    }

    public function portalDashboard(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Authentication required'], 401);
        }

        $clientProfiles = $user->clientProfiles()->with('business:id,name,logo')->get();
        $clientIds = $clientProfiles->pluck('id');

        // Explicit check: user must have at least one client profile
        if ($clientIds->isEmpty()) {
            return response()->json([
                'client_profiles' => [],
                'currency' => 'GHS',
                'stats' => [
                    'total_invoices' => 0,
                    'amount_owed' => 0,
                    'pending_amount' => 0,
                    'total_paid' => 0,
                    'needs_signing' => 0,
                    'active_contracts' => 0,
                ],
                'recent_invoices' => [],
                'recent_contracts' => [],
                'summary_by_business' => [],
            ]);
        }

        $totalInvoices = \App\Models\Invoice::whereIn('client_id', $clientIds)->count();
        $amountOwed = \App\Models\Invoice::whereIn('client_id', $clientIds)
            ->whereIn('status', ['sent', 'viewed', 'overdue'])
            ->selectRaw('SUM(COALESCE(total, 0) - COALESCE(amount_paid, 0)) as owed')->value('owed') ?? 0;
        $totalPaid = \App\Models\Payment::whereIn('client_id', $clientIds)->where('status', 'success')->sum('amount');
        $currency = $clientProfiles->first()?->business?->currency ?? 'GHS';
        $needsSigning = \App\Models\Invoice::whereIn('client_id', $clientIds)
            ->where('client_signature_required', true)->whereNull('client_signed_at')
            ->whereIn('status', ['sent', 'viewed'])->count();
        $needsSigning += \App\Models\Contract::whereIn('client_id', $clientIds)
            ->whereNull('client_signed_at')->whereIn('status', ['sent', 'viewed'])->count();
        $activeContracts = \App\Models\Contract::whereIn('client_id', $clientIds)
            ->where('status', 'signed')->count();

        $recentInvoices = \App\Models\Invoice::whereIn('client_id', $clientIds)
            ->with('business:id,name')->latest()->take(5)->get();
        $recentContracts = \App\Models\Contract::whereIn('client_id', $clientIds)
            ->with('business:id,name')->latest()->take(5)->get();

        // Summary of dealings per company (business name + totals)
        $summaryByBusiness = [];
        foreach ($clientProfiles as $profile) {
            $business = $profile->business;
            if (!$business) {
                continue;
            }
            $invoices = \App\Models\Invoice::where('client_id', $profile->id);
            $paidSum = \App\Models\Payment::where('client_id', $profile->id)->where('status', 'success')->sum('amount');
            $summaryByBusiness[] = [
                'business_id' => $business->id,
                'business_name' => $business->name,
                'total_invoices' => $invoices->count(),
                'total_paid' => (float) $paidSum,
                'currency' => $business->currency ?? $currency,
            ];
        }

        return response()->json([
            'client_profiles' => $clientProfiles,
            'currency' => $currency,
            'stats' => [
                'total_invoices' => $totalInvoices,
                'amount_owed' => $amountOwed,
                'pending_amount' => $amountOwed,
                'total_paid' => (float) $totalPaid,
                'needs_signing' => $needsSigning,
                'active_contracts' => $activeContracts,
            ],
            'recent_invoices' => $recentInvoices,
            'recent_contracts' => $recentContracts,
            'summary_by_business' => $summaryByBusiness,
        ]);
    }

    /**
     * List all payments for the authenticated client (across all their companies).
     */
    public function portalPayments(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Authentication required'], 401);
        }

        $clientIds = $user->clientProfiles()->pluck('id');
        if ($clientIds->isEmpty()) {
            return response()->json(['data' => [], 'meta' => ['total' => 0]]);
        }

        // Additional validation: ensure client IDs are integers
        $clientIds = $clientIds->map(fn ($id) => (int) $id)->filter(fn ($id) => $id > 0);

        $payments = \App\Models\Payment::whereIn('client_id', $clientIds)
            ->with(['invoice:id,invoice_number,total,currency', 'business:id,name'])
            ->latest('paid_at')
            ->latest('id')
            ->paginate($request->per_page ?? 20);

        return response()->json($payments);
    }

    public function initiatePayment(Request $request, Invoice $invoice)
    {
        $user = $request->user();
        $clientIds = $user->clientProfiles()->pluck('id');

        if (!$clientIds->contains($invoice->client_id)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $balanceDue = $invoice->total - $invoice->amount_paid;
        if ($balanceDue <= 0) {
            return response()->json(['message' => 'Invoice is already fully paid'], 422);
        }

        $business = $invoice->business;
        if (!$business || !$business->payment_verified || !$business->paystack_subaccount_code) {
            return response()->json(['message' => 'This business has not set up payment receiving yet. Please contact them directly.'], 422);
        }

        $reference = 'ESP-CLT-' . strtoupper(uniqid()) . '-' . $invoice->id;

        // Create Payment record BEFORE Paystack initialization so webhook can find it
        $payment = \App\Models\Payment::create([
            'business_id' => $business->id,
            'invoice_id' => $invoice->id,
            'client_id' => $invoice->client_id,
            'amount' => $balanceDue,
            'currency' => 'GHS',
            'reference' => $reference,
            'paystack_reference' => $reference,
            'status' => 'pending',
        ]);

        $paystack = app(PaystackService::class);
        $result = $paystack->initializeSplitTransaction([
            'email' => $user->email,
            'amount' => $balanceDue,
            'currency' => 'GHS',
            'reference' => $reference,
            'subaccount_code' => $business->paystack_subaccount_code,
            'callback_url' => config('app.frontend_url') . '/client/dashboard?payment=success&invoice=' . $invoice->id,
            'metadata' => [
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'business_id' => $business->id,
                'client_user_id' => $user->id,
            ],
        ]);

        if (!($result['status'] ?? false)) {
            $payment->update(['status' => 'failed']);
            return response()->json(['message' => $result['message'] ?? 'Payment initialization failed'], 422);
        }

        return response()->json([
            'authorization_url' => $result['data']['authorization_url'],
            'reference' => $reference,
            'amount' => $balanceDue,
        ]);
    }
}
