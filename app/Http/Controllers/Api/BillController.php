<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\Business;
use Illuminate\Http\Request;

class BillController extends Controller
{
    public function index(Request $request, Business $business)
    {
        $query = $business->bills();

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }
        if ($request->filled('category') && $request->category !== 'all') {
            $query->where('category', $request->category);
        }
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('supplier_name', 'like', "%{$request->search}%")
                  ->orWhere('bill_number', 'like', "%{$request->search}%")
                  ->orWhere('description', 'like', "%{$request->search}%");
            });
        }

        // Auto-mark overdue
        $business->bills()
            ->where('status', 'due')
            ->whereDate('due_date', '<', now())
            ->update(['status' => 'overdue']);

        $bills = $query->latest('due_date')->paginate($request->per_page ?? 20);

        $summary = [
            'total_due'     => $business->bills()->whereIn('status', ['due', 'overdue'])->sum('amount'),
            'total_overdue' => $business->bills()->where('status', 'overdue')->sum('amount'),
            'total_paid_month' => $business->bills()->where('status', 'paid')
                ->whereMonth('paid_date', now()->month)->sum('amount_paid'),
        ];

        $counts = [
            'all'     => $business->bills()->count(),
            'due'     => $business->bills()->where('status', 'due')->count(),
            'overdue' => $business->bills()->where('status', 'overdue')->count(),
            'paid'    => $business->bills()->where('status', 'paid')->count(),
        ];

        return response()->json(array_merge($bills->toArray(), ['summary' => $summary, 'counts' => $counts]));
    }

    public function store(Request $request, Business $business)
    {
        $request->validate([
            'supplier_name' => 'required|string|max:255',
            'supplier_email'=> 'nullable|email',
            'bill_number'   => 'nullable|string|max:100',
            'category'      => 'nullable|string|max:100',
            'bill_date'     => 'required|date',
            'due_date'      => 'required|date|after_or_equal:bill_date',
            'amount'        => 'required|numeric|min:0.01',
            'description'   => 'nullable|string',
        ]);

        $bill = $business->bills()->create([
            'supplier_name'  => $request->supplier_name,
            'supplier_email' => $request->supplier_email,
            'bill_number'    => $request->bill_number,
            'category'       => $request->category,
            'bill_date'      => $request->bill_date,
            'due_date'       => $request->due_date,
            'currency'       => $business->currency ?? 'GHS',
            'amount'         => $request->amount,
            'description'    => $request->description,
            'status'         => now()->greaterThan($request->due_date) ? 'overdue' : 'due',
        ]);

        return response()->json(['message' => 'Bill added', 'bill' => $bill], 201);
    }

    public function show(Business $business, Bill $bill)
    {
        if ($bill->business_id !== $business->id) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return response()->json(['bill' => $bill]);
    }

    public function update(Request $request, Business $business, Bill $bill)
    {
        if ($bill->business_id !== $business->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $request->validate([
            'supplier_name' => 'sometimes|string|max:255',
            'due_date'      => 'sometimes|date',
            'amount'        => 'sometimes|numeric|min:0.01',
            'category'      => 'nullable|string|max:100',
            'description'   => 'nullable|string',
        ]);

        $bill->update($request->only([
            'supplier_name', 'supplier_email', 'bill_number', 'category',
            'bill_date', 'due_date', 'amount', 'description',
        ]));

        return response()->json(['message' => 'Bill updated', 'bill' => $bill]);
    }

    public function markPaid(Request $request, Business $business, Bill $bill)
    {
        if ($bill->business_id !== $business->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $request->validate([
            'payment_method'    => 'nullable|string|max:100',
            'payment_reference' => 'nullable|string|max:255',
            'amount_paid'       => 'nullable|numeric|min:0.01',
            'paid_date'         => 'nullable|date',
        ]);

        $amountPaid = $request->amount_paid ?? $bill->amount;
        $bill->update([
            'status'             => 'paid',
            'amount_paid'        => $amountPaid,
            'payment_method'     => $request->payment_method,
            'payment_reference'  => $request->payment_reference,
            'paid_date'          => $request->paid_date ?? today(),
        ]);

        return response()->json(['message' => 'Bill marked as paid', 'bill' => $bill]);
    }

    public function destroy(Business $business, Bill $bill)
    {
        if ($bill->business_id !== $business->id) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $bill->update(['status' => 'cancelled']);
        return response()->json(['message' => 'Bill cancelled']);
    }
}
