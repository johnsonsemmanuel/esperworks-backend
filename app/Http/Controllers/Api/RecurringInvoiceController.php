<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RecurringInvoice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Business;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RecurringInvoiceController extends Controller
{
    public function index(Request $request, Business $business)
    {
        $query = $business->recurringInvoices()->with('client');

        if ($request->status && $request->status !== 'all') {
            if ($request->status === 'active') {
                $query->where('is_active', true);
            } elseif ($request->status === 'inactive') {
                $query->where('is_active', false);
            }
        }

        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('title', 'like', "%{$request->search}%")
                  ->orWhereHas('client', fn($q2) => $q2->where('name', 'like', "%{$request->search}%"));
            });
        }

        $recurringInvoices = $query->latest()->paginate($request->per_page ?? 15);

        return response()->json($recurringInvoices);
    }

    public function store(Request $request, Business $business)
    {
        $request->validate([
            'client_id' => 'required|exists:clients,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string|max:255',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.rate' => 'required|numeric|min:0',
            'frequency' => 'required|in:daily,weekly,biweekly,monthly,quarterly,yearly',
            'interval_count' => 'required|integer|min:1',
            'day_of_month' => 'nullable|integer|min:1|max:31',
            'start_date' => 'required|date|after_or_equal:today',
            'end_date' => 'nullable|date|after:start_date',
            'max_invoices' => 'nullable|integer|min:1',
            'vat_rate' => 'nullable|numeric|min:0|max:100',
            'notes' => 'nullable|string',
        ]);

        // Check plan restrictions
        if (!$business->canCreateRecurringInvoice()) {
            $plan = $business->plan ?? 'free';
            return response()->json([
                'message' => "You're operating at full capacity. Upgrade to keep workflows uninterrupted.",
                'plan' => $plan,
                'plan_name' => \App\Models\Business::planDisplayName($plan),
                'upgrade_required' => true,
            ], 403);
        }

        // Calculate totals
        $subtotal = 0;
        foreach ($request->items as $item) {
            $subtotal += $item['quantity'] * $item['rate'];
        }

        $vatRate = $request->vat_rate ?? $business->vat_rate ?? 0;
        $vatAmount = $subtotal * ($vatRate / 100);
        $total = $subtotal + $vatAmount;

        DB::beginTransaction();
        try {
            $recurringInvoice = RecurringInvoice::create([
                'business_id' => $business->id,
                'client_id' => $request->client_id,
                'title' => $request->title,
                'description' => $request->description,
                'currency' => 'GHS',
                'subtotal' => $subtotal,
                'vat_rate' => $vatRate,
                'vat_amount' => $vatAmount,
                'total' => $total,
                'frequency' => $request->frequency,
                'interval_count' => $request->interval_count,
                'day_of_month' => $request->day_of_month,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'next_invoice_date' => $request->start_date,
                'is_active' => true,
                'max_invoices' => $request->max_invoices,
                'invoices_created' => 0,
                'notes' => $request->notes,
                'items_data' => $request->items,
            ]);

            ActivityLog::log('recurring_invoice.created', "Recurring invoice created: {$recurringInvoice->title}", $recurringInvoice);

            DB::commit();

            return response()->json(['message' => 'Recurring invoice created', 'recurring_invoice' => $recurringInvoice], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function show(Business $business, RecurringInvoice $recurringInvoice)
    {
        if ($recurringInvoice->business_id !== $business->id) {
            return response()->json(['message' => 'Recurring invoice not found'], 404);
        }

        $recurringInvoice->load(['client', 'lastInvoice']);

        return response()->json(['recurring_invoice' => $recurringInvoice]);
    }

    public function update(Request $request, Business $business, RecurringInvoice $recurringInvoice)
    {
        if ($recurringInvoice->business_id !== $business->id) {
            return response()->json(['message' => 'Recurring invoice not found'], 404);
        }

        $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'items' => 'sometimes|array|min:1',
            'items.*.description' => 'required|string|max:255',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.rate' => 'required|numeric|min:0',
            'frequency' => 'sometimes|in:daily,weekly,biweekly,monthly,quarterly,yearly',
            'interval_count' => 'sometimes|integer|min:1',
            'day_of_month' => 'nullable|integer|min:1|max:31',
            'end_date' => 'nullable|date|after:start_date',
            'max_invoices' => 'nullable|integer|min:1',
            'vat_rate' => 'nullable|numeric|min:0|max:100',
            'notes' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        // Recalculate totals if items provided
        if ($request->has('items')) {
            $subtotal = 0;
            foreach ($request->items as $item) {
                $subtotal += $item['quantity'] * $item['rate'];
            }

            $vatRate = $request->vat_rate ?? $recurringInvoice->vat_rate;
            $vatAmount = $subtotal * ($vatRate / 100);
            $total = $subtotal + $vatAmount;

            $request->merge([
                'subtotal' => $subtotal,
                'vat_amount' => $vatAmount,
                'total' => $total,
            ]);
        }

        $recurringInvoice->update($request->all());

        ActivityLog::log('recurring_invoice.updated', "Recurring invoice updated: {$recurringInvoice->title}", $recurringInvoice);

        return response()->json(['message' => 'Recurring invoice updated', 'recurring_invoice' => $recurringInvoice]);
    }

    public function destroy(Business $business, RecurringInvoice $recurringInvoice)
    {
        if ($recurringInvoice->business_id !== $business->id) {
            return response()->json(['message' => 'Recurring invoice not found'], 404);
        }

        $recurringInvoice->delete();

        ActivityLog::log('recurring_invoice.deleted', "Recurring invoice deleted: {$recurringInvoice->title}", $recurringInvoice);

        return response()->json(['message' => 'Recurring invoice deleted']);
    }

    public function pause(Business $business, RecurringInvoice $recurringInvoice)
    {
        if ($recurringInvoice->business_id !== $business->id) {
            return response()->json(['message' => 'Recurring invoice not found'], 404);
        }

        $recurringInvoice->update(['is_active' => false]);

        ActivityLog::log('recurring_invoice.paused', "Recurring invoice paused: {$recurringInvoice->title}", $recurringInvoice);

        return response()->json(['message' => 'Recurring invoice paused']);
    }

    public function resume(Business $business, RecurringInvoice $recurringInvoice)
    {
        if ($recurringInvoice->business_id !== $business->id) {
            return response()->json(['message' => 'Recurring invoice not found'], 404);
        }

        $recurringInvoice->update(['is_active' => true]);

        ActivityLog::log('recurring_invoice.resumed', "Recurring invoice resumed: {$recurringInvoice->title}", $recurringInvoice);

        return response()->json(['message' => 'Recurring invoice resumed']);
    }

    public function generateInvoice(Business $business, RecurringInvoice $recurringInvoice)
    {
        if ($recurringInvoice->business_id !== $business->id) {
            return response()->json(['message' => 'Recurring invoice not found'], 404);
        }

        // Check if we can create an invoice
        if (!$business->canCreateInvoice()) {
            $plan = $business->plan ?? 'free';
            return response()->json([
                'message' => "You're operating at full capacity. Upgrade to keep workflows uninterrupted.",
                'plan' => $plan,
                'plan_name' => \App\Models\Business::planDisplayName($plan),
                'upgrade_required' => true,
            ], 403);
        }

        DB::beginTransaction();
        try {
            // Create the invoice
            $invoice = Invoice::create([
                'business_id' => $business->id,
                'client_id' => $recurringInvoice->client_id,
                'invoice_number' => $business->generateInvoiceNumber(),
                'status' => 'draft',
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'currency' => $recurringInvoice->currency,
                'subtotal' => $recurringInvoice->subtotal,
                'vat_rate' => $recurringInvoice->vat_rate,
                'vat_amount' => $recurringInvoice->vat_amount,
                'total' => $recurringInvoice->total,
                'notes' => $recurringInvoice->notes,
            ]);

            // Create invoice items
            foreach ($recurringInvoice->items_data as $itemData) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => $itemData['description'],
                    'quantity' => $itemData['quantity'],
                    'rate' => $itemData['rate'],
                    'total' => $itemData['quantity'] * $itemData['rate'],
                ]);
            }

            // Update recurring invoice
            $recurringInvoice->update([
                'last_invoice_id' => $invoice->id,
                'invoices_created' => $recurringInvoice->invoices_created + 1,
                'next_invoice_date' => $recurringInvoice->calculateNextDate(),
            ]);

            // Check if we should deactivate
            if ($recurringInvoice->shouldDeactivate()) {
                $recurringInvoice->update(['is_active' => false]);
            }

            ActivityLog::log('recurring_invoice.generated', "Invoice generated from recurring: {$recurringInvoice->title}", $invoice);

            DB::commit();

            return response()->json(['message' => 'Invoice generated', 'invoice' => $invoice], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
