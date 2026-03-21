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
        $query = $business->creditNotes()->with('client:id,name', 'invoice:id,invoice_number');

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('credit_note_number', 'like', "%{$request->search}%")
                  ->orWhereHas('client', fn ($q2) => $q2->where('name', 'like', "%{$request->search}%"));
            });
        }

        return response()->json($query->latest()->paginate($request->per_page ?? 15));
    }

    public function store(Request $request, Business $business)
    {
        $request->validate([
            'client_id'  => 'required|exists:clients,id',
            'invoice_id' => 'nullable|exists:invoices,id',
            'issue_date' => 'required|date',
            'reason'     => 'nullable|string|max:255',
            'notes'      => 'nullable|string',
            'items'      => 'required|array|min:1',
            'items.*.description' => 'required|string',
            'items.*.quantity'    => 'required|numeric|min:0.01',
            'items.*.rate'        => 'required|numeric|min:0',
        ]);

        if (!$business->clients()->where('id', $request->client_id)->exists()) {
            return response()->json(['message' => 'Client not found'], 422);
        }

        // Validate invoice belongs to business if provided
        if ($request->invoice_id) {
            $invoice = $business->invoices()->find($request->invoice_id);
            if (!$invoice) return response()->json(['message' => 'Invoice not found'], 422);
        }

        $items    = $request->items;
        $subtotal = collect($items)->sum(fn ($i) => $i['quantity'] * $i['rate']);
        $items    = collect($items)->map(fn ($i) => array_merge($i, ['amount' => $i['quantity'] * $i['rate']]))->toArray();

        $prefix = 'CN-' . date('Y') . '-';
        $last   = CreditNote::where('business_id', $business->id)->count() + 1;
        $number = $prefix . str_pad($last, 4, '0', STR_PAD_LEFT);

        $creditNote = $business->creditNotes()->create([
            'client_id'          => $request->client_id,
            'invoice_id'         => $request->invoice_id,
            'credit_note_number' => $number,
            'status'             => 'issued',
            'issue_date'         => $request->issue_date,
            'currency'           => $business->currency ?? 'GHS',
            'subtotal'           => $subtotal,
            'vat_amount'         => 0,
            'total'              => $subtotal,
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
