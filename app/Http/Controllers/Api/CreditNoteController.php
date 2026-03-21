<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\CreditNote;
use App\Models\Invoice;
use Illuminate\Http\Request;

class CreditNoteController extends Controller
{
    public function index(Request $request, Business $business)
    {
        $query = $business->creditNotes()->with('invoice:id,invoice_number');

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('credit_note_number', 'like', "%{$request->search}%")
                  ->orWhere('reason', 'like', "%{$request->search}%");
            });
        }

        $paginated = $query->latest()->paginate($request->per_page ?? 15);

        $counts = [
            'all'     => $business->creditNotes()->count(),
            'issued'  => $business->creditNotes()->where('status', 'issued')->count(),
            'applied' => $business->creditNotes()->where('status', 'applied')->count(),
            'void'    => $business->creditNotes()->where('status', 'void')->count(),
        ];

        $summary = [
            'total_issued'  => $business->creditNotes()->where('status', 'issued')->sum('total'),
            'total_applied' => $business->creditNotes()->where('status', 'applied')->sum('total'),
            'total_void'    => $business->creditNotes()->where('status', 'void')->sum('total'),
        ];

        return response()->json(array_merge($paginated->toArray(), [
            'counts'  => $counts,
            'summary' => $summary,
        ]));
    }

    public function store(Request $request, Business $business)
    {
        $request->validate([
            'client_id'  => 'nullable|exists:clients,id',
            'invoice_id' => 'nullable|exists:invoices,id',
            'issue_date' => 'required|date',
            'reason'     => 'required|string|max:255',
            'currency'   => 'nullable|string|max:10',
            'notes'      => 'nullable|string',
            // Line-item form
            'items'               => 'sometimes|array|min:1',
            'items.*.description' => 'required_with:items|string',
            'items.*.quantity'    => 'required_with:items|numeric|min:0.01',
            'items.*.rate'        => 'required_with:items|numeric|min:0',
            // Simple-amount form (used by dashboard quick-create)
            'subtotal'   => 'required_without:items|numeric|min:0.01',
            'vat_amount' => 'nullable|numeric|min:0',
        ]);

        if ($request->client_id && !$business->clients()->where('id', $request->client_id)->exists()) {
            return response()->json(['message' => 'Client not found'], 422);
        }

        // Validate invoice belongs to business if provided
        if ($request->invoice_id) {
            $invoice = $business->invoices()->find($request->invoice_id);
            if (!$invoice) return response()->json(['message' => 'Invoice not found'], 422);
        }

        // Support both line-item and direct-amount creation
        if ($request->has('items')) {
            $items    = $request->items;
            $subtotal = collect($items)->sum(fn ($i) => $i['quantity'] * $i['rate']);
            $items    = collect($items)->map(fn ($i) => array_merge($i, ['amount' => $i['quantity'] * $i['rate']]))->toArray();
        } else {
            $subtotal = (float) $request->subtotal;
            $items    = [['description' => $request->reason, 'quantity' => 1, 'rate' => $subtotal, 'amount' => $subtotal]];
        }

        $prefix = 'CN-' . date('Y') . '-';
        $last   = CreditNote::where('business_id', $business->id)->count() + 1;
        $number = $prefix . str_pad($last, 4, '0', STR_PAD_LEFT);

        $creditNote = $business->creditNotes()->create([
            'client_id'          => $request->client_id,
            'invoice_id'         => $request->invoice_id,
            'credit_note_number' => $number,
            'status'             => 'issued',
            'issue_date'         => $request->issue_date,
            'currency'           => $request->currency ?? $business->currency ?? 'GHS',
            'subtotal'           => $subtotal,
            'vat_amount'         => (float) ($request->vat_amount ?? 0),
            'total'              => $subtotal + (float) ($request->vat_amount ?? 0),
            'reason'             => $request->reason,
            'notes'              => $request->notes,
            'items'              => $items,
        ]);

        return response()->json(['message' => 'Credit note issued', 'credit_note' => $creditNote->load('client', 'invoice')], 201);
    }

    public function show(Business $business, CreditNote $creditNote)
    {
        if ($creditNote->business_id !== $business->id) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return response()->json(['credit_note' => $creditNote->load('client', 'invoice')]);
    }

    public function void(Business $business, CreditNote $creditNote)
    {
        if ($creditNote->business_id !== $business->id) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if ($creditNote->status === 'applied') {
            return response()->json(['message' => 'Applied credit notes cannot be voided'], 422);
        }
        $creditNote->update(['status' => 'void']);
        return response()->json(['message' => 'Credit note voided']);
    }
}
