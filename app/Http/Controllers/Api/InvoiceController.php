<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Business;
use App\Models\ActivityLog;
use App\Mail\InvoiceMail;
use App\Services\InvoiceService;
use App\Services\PdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class InvoiceController extends Controller
{
    public function index(Request $request, Business $business)
    {
        $query = $business->invoices()->with('client:id,name,email', 'contract:id,contract_number,type,business_id');

        if ($request->trashed === 'only') {
            $query->onlyTrashed();
        } elseif ($request->trashed === 'with') {
            $query->withTrashed();
        }

        if ($request->status && $request->status !== 'all') {
            $query->where('status', $request->status);
        }
        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('invoice_number', 'like', "%{$request->search}%")
                    ->orWhereHas('client', fn($q2) => $q2->where('name', 'like', "%{$request->search}%"));
            });
        }
        if ($request->from)
            $query->where('issue_date', '>=', $request->from);
        if ($request->to)
            $query->where('issue_date', '<=', $request->to);

        if ($request->filled('date_filter')) {
            $now = now();
            switch ($request->date_filter) {
                case 'this_month':
                    $query->whereBetween('issue_date', [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()]);
                    break;
                case 'last_month':
                    $lastMonth = $now->copy()->subMonth();
                    $query->whereBetween('issue_date', [$lastMonth->copy()->startOfMonth(), $lastMonth->copy()->endOfMonth()]);
                    break;
                case 'this_year':
                    $query->whereYear('issue_date', $now->year);
                    break;
            }
        }

        $invoices = $query->latest()->paginate($request->per_page ?? 15);

        $counts = [
            'all' => $business->invoices()->count(),
            'draft' => $business->invoices()->where('status', 'draft')->count(),
            'sent' => $business->invoices()->where('status', 'sent')->count(),
            'viewed' => $business->invoices()->where('status', 'viewed')->count(),
            'paid' => $business->invoices()->where('status', 'paid')->count(),
            'partial' => $business->invoices()->whereIn('status', ['partial', 'partially_paid'])->count(),
            'overdue' => $business->invoices()->where('status', 'overdue')->count(),
            'deleted' => $business->invoices()->onlyTrashed()->count(),
        ];

        return response()->json(array_merge($invoices->toArray(), ['counts' => $counts]));
    }

    public function store(Request $request, Business $business, InvoiceService $service)
    {
        $request->validate([
            'client_id' => [
                'required',
                'exists:clients,id',
                function ($attribute, $value, $fail) use ($business) {
                    if (!$business->clients()->where('id', $value)->exists()) {
                        $fail('The selected client does not belong to this business.');
                    }
                }
            ],
            'contract_id' => [
                'nullable',
                'exists:contracts,id',
                function ($attribute, $value, $fail) use ($business) {
                    if ($value && !$business->contracts()->where('id', $value)->exists()) {
                        $fail('The selected contract does not belong to this business.');
                    }
                }
            ],
            'issue_date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:issue_date',
            'currency' => 'nullable|string|max:10',
            'vat_rate' => 'nullable|numeric|min:0|max:100',
            'notes' => 'nullable|string',
            'payment_method' => 'nullable|string',
            'signature_required' => 'boolean',
            'client_signature_required' => 'boolean',
            'business_signature_name' => 'nullable|string',
            'business_signature_image' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.rate' => 'required|numeric|min:0',
        ]);

        if (!$business->canCreateInvoice()) {
            $plan = $business->plan ?? 'free';
            return response()->json([
                'message' => "You're operating at full capacity. Upgrade to keep workflows uninterrupted.",
                'plan' => $plan,
                'plan_name' => Business::planDisplayName($plan),
                'upgrade_required' => true,
            ], 403);
        }

        $invoice = $service->createDraft($business, $request->all());

        return response()->json([
            'message' => 'Invoice created successfully',
            'invoice' => $invoice,
        ], 201);
    }

    public function show($business, $invoice)
    {
        $businessObj = $business instanceof Business ? $business : Business::findOrFail($business);
        $invoiceObj = $invoice instanceof Invoice ? $invoice : Invoice::findOrFail($invoice);

        if ($invoiceObj->business_id !== $businessObj->id) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }
        $invoiceObj->load(['client', 'items', 'payments', 'contract:id,contract_number,type,title']);
        $invoiceObj->makeVisible('signing_token');
        return response()->json(['invoice' => $invoiceObj]);
    }

    public function update(Request $request, $business, $invoice, InvoiceService $service)
    {
        $businessObj = $business instanceof Business ? $business : Business::findOrFail($business);
        $invoiceObj = $invoice instanceof Invoice ? $invoice : Invoice::findOrFail($invoice);

        if ($invoiceObj->business_id !== $businessObj->id) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }
        
        // Prevent editing invoices with payments
        if ($invoiceObj->payments()->where('status', 'success')->exists()) {
            return response()->json([
                'message' => 'Invoices with payments cannot be edited. Create a credit note instead.',
                'has_payments' => true,
                'payment_count' => $invoiceObj->payments()->where('status', 'success')->count(),
                'total_paid' => $invoiceObj->amount_paid,
                'invoice_total' => $invoiceObj->total
            ], 422);
        }
        
        // Prevent editing paid invoices
        if ($invoiceObj->status === 'paid') {
            return response()->json([
                'message' => 'Paid invoices cannot be edited. Create a credit note instead.',
                'is_paid' => true
            ], 422);
        }
        
        if (!in_array($invoiceObj->status, ['draft'])) {
            return response()->json(['message' => 'Only draft invoices can be edited'], 422);
        }

        $request->validate([
            'client_id' => 'sometimes|exists:clients,id',
            'issue_date' => 'sometimes|date',
            'due_date' => 'sometimes|date|after_or_equal:issue_date',
            'date' => 'sometimes|date', // frontend may send "date" instead of issue_date
            'currency' => 'nullable|string|max:10',
            'vat_rate' => 'nullable|numeric|min:0|max:100',
            'notes' => 'nullable|string',
            'payment_method' => 'nullable|string',
            'items' => 'sometimes|array|min:1',
            'items.*.description' => 'required|string',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.rate' => 'nullable|numeric|min:0',
            'items.*.unit_price' => 'nullable|numeric|min:0',
        ]);

        $payload = $request->all();
        if (empty($payload['issue_date']) && !empty($payload['date'])) {
            $payload['issue_date'] = $payload['date'];
        }
        $invoiceObj = $service->updateDraft($invoiceObj, $payload);

        return response()->json(['message' => 'Invoice updated', 'invoice' => $invoiceObj]);
    }

    public function destroy($business, $invoice)
    {
        $businessObj = $business instanceof Business ? $business : Business::findOrFail($business);
        $invoiceObj = $invoice instanceof Invoice ? $invoice : Invoice::findOrFail($invoice);

        if ($invoiceObj->business_id !== $businessObj->id) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }
        if (!in_array($invoiceObj->status, ['draft', 'sent', 'cancelled'])) {
            return response()->json(['message' => 'Only draft, sent, or cancelled invoices can be deleted'], 422);
        }

        $invoiceObj->delete();
        ActivityLog::log('invoice.deleted', "Invoice {$invoiceObj->invoice_number} deleted");

        return response()->json(['message' => 'Invoice deleted']);
    }

    public function send($business, $invoice)
    {
        $businessObj = $business instanceof Business ? $business : Business::findOrFail($business);
        $invoiceObj = $invoice instanceof Invoice ? $invoice : Invoice::findOrFail($invoice);

        if ($invoiceObj->business_id !== $businessObj->id) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }
        $invoiceObj->load(['business', 'client']);

        if (!$invoiceObj->client) {
            return response()->json(['message' => 'This invoice has no client. Add a client before sending.'], 422);
        }
        if (empty($invoiceObj->client->email)) {
            return response()->json(['message' => 'The client has no email address. Please add an email to the client before sending.'], 422);
        }

        $clientEmailSent = false;
        $clientEmailError = null;
        $businessEmailSent = false;
        $businessEmailError = null;
        
        // Send to client with detailed error handling
        try {
            Mail::to($invoiceObj->client->email)->send(new InvoiceMail($invoiceObj, 'send'));
            $clientEmailSent = true;
        } catch (\Exception $e) {
            \Log::warning('Failed to send client email: ' . $e->getMessage(), [
                'invoice_id' => $invoiceObj->id,
                'invoice_number' => $invoiceObj->invoice_number,
                'client_email' => $invoiceObj->client->email,
                'business_id' => $businessObj->id,
                'error' => $e->getMessage()
            ]);
            $clientEmailError = $e->getMessage();
        }

        // Send to business with detailed error handling
        try {
            if (!empty($businessObj->email)) {
                Mail::to($businessObj->email)->send(new InvoiceMail($invoiceObj, 'notification'));
                $businessEmailSent = true;
            } else {
                \Log::info('Business email not configured, skipping notification', [
                    'invoice_id' => $invoiceObj->id,
                    'business_id' => $businessObj->id
                ]);
            }
        } catch (\Exception $e) {
            \Log::warning('Failed to send business notification email: ' . $e->getMessage(), [
                'invoice_id' => $invoiceObj->id,
                'invoice_number' => $invoiceObj->invoice_number,
                'business_email' => $businessObj->email,
                'business_id' => $businessObj->id,
                'error' => $e->getMessage()
            ]);
            $businessEmailError = $e->getMessage();
        }

        try {
            \App\Events\InvoiceSent::dispatch(
                $invoiceObj->id,
                $invoiceObj->client_id,
                $invoiceObj->business_id,
                $invoiceObj->invoice_number,
                floatval($invoiceObj->total)
            );
        } catch (\Exception $e) {
            \Log::warning('Failed to dispatch InvoiceSent event: ' . $e->getMessage());
        }

        // Consistent status determination logic
        $isFullyPaid = floatval($invoiceObj->amount_paid) >= floatval($invoiceObj->total);
        $hasPayments = $invoiceObj->payments()->exists();
        $amountDue = $invoiceObj->total - $invoiceObj->amount_paid;

        // Determine correct status based on payment state
        if ($isFullyPaid) {
            $newStatus = 'paid';
        } elseif ($hasPayments && $amountDue > 0) {
            $newStatus = 'partial';
        } elseif ($amountDue > 0 && $invoiceObj->due_date && now()->isAfter($invoiceObj->due_date)) {
            $newStatus = 'overdue';
        } else {
            $newStatus = 'sent';
        }

        // Update invoice status and timestamps
        $updateData = [
            'sent_at' => now(),
            'status' => $newStatus,
        ];

        // Set paid_at timestamp if fully paid and not already set
        if ($newStatus === 'paid' && !$invoiceObj->paid_at) {
            $updateData['paid_at'] = now();
        }

        // Set viewed_at if not already set and status is sent/partial/overdue
        if (in_array($newStatus, ['sent', 'partial', 'overdue']) && !$invoiceObj->viewed_at) {
            $updateData['viewed_at'] = now();
        }

        $invoiceObj->update($updateData);

        // Log appropriate activity
        $activityDescription = match ($newStatus) {
            'paid' => "Invoice {$invoiceObj->invoice_number} marked as paid",
            'partial' => "Invoice {$invoiceObj->invoice_number} marked as partially paid",
            'overdue' => "Invoice {$invoiceObj->invoice_number} marked as overdue",
            'sent' => "Invoice {$invoiceObj->invoice_number} sent to {$invoiceObj->client->email}",
            default => "Invoice {$invoiceObj->invoice_number} sent to {$invoiceObj->client->email}",
        };

        ActivityLog::log('invoice.sent', $activityDescription, $invoiceObj);

        // Determine appropriate response message based on email delivery status
        if ($clientEmailSent && $businessEmailSent) {
            $message = 'Invoice sent successfully to client and business.';
        } elseif ($clientEmailSent && !$businessEmailSent) {
            $message = 'Invoice sent to client successfully. Business notification failed.';
        } elseif (!$clientEmailSent && $businessEmailSent) {
            $message = 'Invoice marked as sent, but client email failed. Business notified successfully.';
        } else {
            $message = 'Invoice marked as sent, but email delivery failed for both client and business. Please check email addresses and try again or share the invoice link manually.';
        }

        // Build WhatsApp share link for invoice payment
        $paymentUrl = config('app.frontend_url') . '/pay/' . $invoiceObj->signing_token;
        $currency = $invoiceObj->currency ?? 'GHS';
        $currencySymbol = match($currency) { 'GHS' => 'GH₵', 'USD' => '$', 'EUR' => '€', 'GBP' => '£', default => $currency . ' ' };
        $whatsappMessage = urlencode(
            "Hi {$invoiceObj->client->name},\n\n"
            . "{$businessObj->name} has sent you an invoice.\n\n"
            . "📄 Invoice: {$invoiceObj->invoice_number}\n"
            . "💰 Amount: {$currencySymbol}" . number_format($invoiceObj->amountDue(), 2) . "\n"
            . "📅 Due: " . ($invoiceObj->due_date ? $invoiceObj->due_date->format('M d, Y') : 'On receipt') . "\n\n"
            . "View & Pay: {$paymentUrl}\n\n"
            . "Powered by EsperWorks"
        );
        $whatsappUrl = "https://wa.me/?text={$whatsappMessage}";

        return response()->json([
            'message' => $message,
            'email_sent' => $clientEmailSent,
            'business_email_sent' => $businessEmailSent,
            'email_error' => $clientEmailError,
            'business_email_error' => $businessEmailError,
            'client_email' => $invoiceObj->client->email,
            'business_email' => $businessObj->email,
            'invoice_number' => $invoiceObj->invoice_number,
            'status' => $newStatus,
            'sent_at' => $invoiceObj->sent_at,
            'payment_url' => $paymentUrl,
            'whatsapp_url' => $whatsappUrl,
        ]);
    }

    public function sendReminder($business, $invoice)
    {
        $businessObj = $business instanceof Business ? $business : Business::findOrFail($business);
        $invoiceObj = $invoice instanceof Invoice ? $invoice : Invoice::findOrFail($invoice);

        if ($invoiceObj->business_id !== $businessObj->id) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }
        $invoiceObj->load(['business', 'client']);

        try {
            Mail::to($invoiceObj->client->email)->send(new InvoiceMail($invoiceObj, 'reminder'));
        } catch (\Exception $e) {
            \Log::warning('Failed to send reminder email: ' . $e->getMessage());
        }
        ActivityLog::log('invoice.reminder', "Reminder sent for {$invoiceObj->invoice_number}", $invoiceObj);

        return response()->json(['message' => 'Reminder sent']);
    }

    public function resendSignature($business, $invoice)
    {
        $businessObj = $business instanceof Business ? $business : Business::findOrFail($business);
        $invoiceObj = $invoice instanceof Invoice ? $invoice : Invoice::findOrFail($invoice);

        if ($invoiceObj->business_id !== $businessObj->id) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }
        $invoiceObj->load(['business', 'client']);

        if (!$invoiceObj->client || empty($invoiceObj->client->email)) {
            return response()->json(['message' => 'Client email is required to resend signature request'], 422);
        }

        // Regenerate signing token if expired
        if ($invoiceObj->isTokenExpired()) {
            $invoiceObj->update([
                'signing_token' => \Illuminate\Support\Str::random(64),
                'token_expires_at' => now()->addDays(30),
            ]);
        }

        try {
            Mail::to($invoiceObj->client->email)->send(new InvoiceMail($invoiceObj, 'signature_request'));
        } catch (\Exception $e) {
            \Log::warning('Failed to send signature request email: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to send signature request. Please try again.'], 500);
        }

        ActivityLog::log('invoice.signature_resent', "Signature request resent for {$invoiceObj->invoice_number}", $invoiceObj);

        return response()->json([
            'message' => 'Signature request sent successfully',
            'client_email' => $invoiceObj->client->email,
        ]);
    }

    public function sign(Request $request, Invoice $invoice)
    {
        $request->validate([
            'type' => 'required|in:business,client',
            'signature_name' => 'required|string|max:255',
            'signature_image' => 'nullable|string',
            'signature_type' => 'nullable|string',
            'token' => 'nullable|string',
        ]);

        // Check if token has expired
        if ($invoice->isTokenExpired()) {
            return response()->json(['message' => 'Invoice link has expired. Please contact the business for a new link.'], 410);
        }

        if ($request->type === 'client') {
            // Client signatures require a valid signing token OR authenticated client user
            if (!$request->token || $request->token !== $invoice->signing_token) {
                $user = auth('sanctum')->user();
                if (!$user) {
                    return response()->json(['message' => 'Invalid or missing signing token'], 403);
                }
                // Verify this user is linked to the invoice's client
                $clientIds = $user->clientProfiles()->pluck('id');
                if (!$clientIds->contains($invoice->client_id)) {
                    return response()->json(['message' => 'You are not authorized to sign this document'], 403);
                }
            }
        } else {
            // Business signatures require authentication + ownership
            $user = auth('sanctum')->user();
            if (!$user) {
                return response()->json(['message' => 'Authentication required'], 401);
            }
            $invoice->load('business');
            if ($user->id !== $invoice->business->user_id && !$user->isAdmin()) {
                return response()->json(['message' => 'You are not authorized to sign this document'], 403);
            }
        }

        $field = $request->type === 'business' ? 'business' : 'client';

        $invoice->update([
            "{$field}_signature_name" => $request->signature_name,
            "{$field}_signature_image" => $request->signature_image,
            "{$field}_signed_at" => now(),
        ]);

        app(PdfService::class)->generateInvoicePdf($invoice);
        ActivityLog::log('invoice.signed', "{$request->type} signed invoice {$invoice->invoice_number}", $invoice);

        return response()->json(['message' => ucfirst($request->type) . ' signature applied', 'invoice' => $invoice]);
    }

    public function downloadPdf($business, $invoice)
    {
        $businessObj = $business instanceof Business ? $business : Business::findOrFail($business);
        $invoiceObj = $invoice instanceof Invoice ? $invoice : Invoice::findOrFail($invoice);

        if ($invoiceObj->business_id !== $businessObj->id) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }
        try {
            return app(PdfService::class)->streamInvoicePdf($invoiceObj);
        } catch (\Throwable $e) {
            Log::error('Invoice PDF generation failed', [
                'invoice_id' => $invoiceObj->id,
                'business_id' => $businessObj->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to generate invoice PDF. Please try again.',
            ], 500);
        }
    }

    public function downloadReceipt($business, $invoice)
    {
        $businessObj = $business instanceof Business ? $business : Business::findOrFail($business);
        $invoiceObj = $invoice instanceof Invoice ? $invoice : Invoice::findOrFail($invoice);

        if ($invoiceObj->business_id !== $businessObj->id) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        // Only allow receipt download for paid invoices
        if ($invoiceObj->status !== 'paid') {
            return response()->json(['message' => 'Receipt only available for paid invoices'], 422);
        }

        // Get the latest payment for this invoice
        $payment = $invoiceObj->payments()->latest()->first();
        
        if (!$payment) {
            return response()->json(['message' => 'No payment record found for this invoice'], 404);
        }

        return app(PdfService::class)->streamReceiptPdf($payment);
    }

    public function duplicate(Business $business, Invoice $invoice)
    {
        if ($invoice->business_id !== $business->id) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        // Check plan limits
        if (!$business->canCreateInvoice()) {
            $limits = $business->getPlanLimits();
            $usage = $business->invoices()->whereMonth('created_at', now()->month)->count();
            
            $upgradeData = \App\Services\UpgradeRecommendationService::generateUpgradeMessage(
                $business, 
                'invoices',
                ['usage' => $usage, 'limit' => $limits['invoices'] ?? 5]
            );
            
            return response()->json($upgradeData, 403);
        }

        // Create duplicate based on the given invoice and business
        $invoice->loadMissing('items');

        $newInvoice = $invoice->replicate();
        $newInvoice->invoice_number = $business->generateInvoiceNumber();
        $newInvoice->status = 'draft';
        $newInvoice->issue_date = now()->toDateString();
        $newInvoice->due_date = now()->addDays(30)->toDateString();
        $newInvoice->sent_at = null;
        $newInvoice->viewed_at = null;
        $newInvoice->paid_at = null;
        $newInvoice->amount_paid = 0;
        $newInvoice->business_signature_name = null;
        $newInvoice->business_signature_image = null;
        $newInvoice->business_signed_at = null;
        $newInvoice->client_signature_name = null;
        $newInvoice->client_signature_image = null;
        $newInvoice->client_signed_at = null;
        $newInvoice->signing_token = Str::random(32);
        $newInvoice->pdf_path = null;
        $newInvoice->save();

        // Duplicate items
        foreach ($invoice->items as $item) {
            $newItem = $item->replicate();
            $newItem->invoice_id = $newInvoice->id;
            $newItem->save();
        }

        app(PdfService::class)->generateInvoicePdf($newInvoice);
        ActivityLog::log('invoice.created', "Invoice {$newInvoice->invoice_number} created from {$invoice->invoice_number}", $newInvoice);

        return response()->json([
            'message' => 'Invoice duplicated successfully',
            'invoice' => $newInvoice->load(['client', 'items'])
        ], 201);
    }

    public function markAsPaid($business, $invoice)
    {
        $businessObj = $business instanceof Business ? $business : Business::findOrFail($business);
        $invoiceObj = $invoice instanceof Invoice ? $invoice : Invoice::findOrFail($invoice);

        if ($invoiceObj->business_id !== $businessObj->id) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }
        $invoiceObj->markAsPaid();
        $invoiceObj->load(['business', 'client']);

        try {
            Mail::to($invoiceObj->client->email)->send(new InvoiceMail($invoiceObj, 'receipt'));
        } catch (\Exception $e) {
            \Log::warning('Failed to send payment receipt email: ' . $e->getMessage());
        }
        ActivityLog::log('invoice.paid', "Invoice {$invoiceObj->invoice_number} marked as paid", $invoiceObj);

        return response()->json(['message' => 'Invoice marked as paid', 'invoice' => $invoiceObj]);
    }

    // Client-facing: view by signing token
    public function viewByToken(string $token)
    {
        $invoice = Invoice::where('signing_token', $token)->with(['business', 'client', 'items'])->first();

        if (!$invoice) {
            return response()->json(['message' => 'Invoice not found or link has expired'], 404);
        }

        if ($invoice->isTokenExpired()) {
            return response()->json(['message' => 'Invoice link has expired. Please contact the business for a new link.'], 410);
        }

        if (!$invoice->viewed_at) {
            // Only update status to 'viewed' if not already paid
            $newStatus = $invoice->status === 'sent' ? 'viewed' : $invoice->status;
            if ($invoice->status !== 'paid') {
                $invoice->update(['viewed_at' => now(), 'status' => $newStatus]);
            } else {
                // For paid invoices, just update viewed_at but keep paid status
                $invoice->update(['viewed_at' => now()]);
            }
        }

        return response()->json(['invoice' => $invoice]);
    }

    // Public: view invoice for payment pages (accepts signing_token or numeric ID)
    public function viewForPayment(string $token)
    {
        // Try signing_token first (preferred, used in shared links)
        $invoice = Invoice::where('signing_token', $token)->first();
        
        if ($invoice && $invoice->isTokenExpired()) {
            return response()->json(['message' => 'Invoice link has expired. Please contact the business for a new link.'], 410);
        }

        // Fall back to numeric ID for backward compatibility (e.g. /invoices/pay/4)
        if (!$invoice && is_numeric($token)) {
            $invoice = Invoice::where('id', (int) $token)->first();
        }

        if (!$invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        $invoice->load([
            'business',
            'client',
            'items',
            'payments' => function ($q) {
                $q->where('status', 'success');
            }
        ]);

        return response()->json(['invoice' => $invoice]);
    }

    public function signByToken(Request $request, string $token)
    {
        $invoice = Invoice::where('signing_token', $token)->firstOrFail();

        // Reject expired links the same way as viewByToken / viewForPayment
        if ($invoice->isTokenExpired()) {
            return response()->json([
                'message' => 'Invoice link has expired. Please contact the business for a new link.',
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

        // If client signature is not required, avoid confusing extra signatures
        if ($invoice->client_signature_required === false) {
            return response()->json([
                'message' => 'Client signature is not required for this invoice.',
            ], 422);
        }

        // Prevent duplicate client signing via token
        if ($invoice->client_signed_at) {
            return response()->json([
                'message' => 'This invoice has already been signed by the client.',
            ], 422);
        }

        // Update invoice with client signature (keep current status, signing is not payment)
        $invoice->update([
            'client_signature_name' => $request->signature_name,
            'client_signature_image' => $request->signature_image,
            'client_signed_at' => now(),
        ]);

        // Log activity
        ActivityLog::create([
            'business_id' => $invoice->business_id,
            'user_id' => null, // Public signing
            'action' => 'invoice_signed',
            'description' => "Invoice {$invoice->invoice_number} signed by client via token",
            'details' => json_encode(['invoice_id' => $invoice->id, 'signer' => $request->signature_name]),
        ]);

        return response()->json(['message' => 'Invoice signed successfully']);
    }

    /**
     * Public: Download invoice PDF by signing token
     */
    public function downloadPdfByToken(string $token)
    {
        $invoice = Invoice::where('signing_token', $token)->first();

        if (!$invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        try {
            return app(PdfService::class)->streamInvoicePdf($invoice);
        } catch (\Throwable $e) {
            Log::error('Public invoice PDF generation failed', [
                'invoice_id' => $invoice->id,
                'token' => $token,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to generate invoice PDF. Please try again.',
            ], 500);
        }
    }
}
