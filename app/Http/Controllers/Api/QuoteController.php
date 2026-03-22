<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Contract;
use App\Services\InvoiceService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class QuoteController extends Controller
{
    /** List all quotes (contracts with type = 'quote') for a business */
    public function index(Request $request, Business $business)
    {
        $query = $business->contracts()->where('type', 'quote');

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('quote_status', $request->status);
        }
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('title', 'like', "%{$request->search}%")
                  ->orWhereHas('client', fn ($q2) => $q2->where('name', 'like', "%{$request->search}%"));
            });
        }

        $quotes = $query->with('client:id,name,email')->latest()->paginate($request->per_page ?? 15);

        $statusCounts = $business->contracts()
            ->where('type', 'quote')
            ->selectRaw('quote_status, count(*) as total')
            ->groupBy('quote_status')
            ->pluck('total', 'quote_status')
            ->toArray();

        $counts = [
            'all'      => array_sum($statusCounts),
            'draft'    => $statusCounts['draft'] ?? 0,
            'sent'     => $statusCounts['sent'] ?? 0,
            'accepted' => $statusCounts['accepted'] ?? 0,
            'declined' => $statusCounts['declined'] ?? 0,
            'expired'  => $statusCounts['expired'] ?? 0,
        ];

        return response()->json(array_merge($quotes->toArray(), ['counts' => $counts]));
    }

    public function store(Request $request, Business $business)
    {
        $request->validate([
            'client_id'        => 'required|exists:clients,id',
            'title'            => 'required|string|max:255',
            'quote_valid_until'=> 'nullable|date|after_or_equal:today',
            'quote_vat_rate'   => 'nullable|numeric|min:0|max:100',
            'notes'            => 'nullable|string',
            'items'            => 'required|array|min:1',
            'items.*.description' => 'required|string',
            'items.*.quantity'    => 'required|numeric|min:0.01',
            'items.*.rate'        => 'required|numeric|min:0',
        ]);

        // Validate client belongs to this business
        if (!$business->clients()->where('id', $request->client_id)->exists()) {
            return response()->json(['message' => 'Client not found'], 422);
        }

        $items = $request->items;
        $subtotal = collect($items)->sum(fn ($i) => $i['quantity'] * $i['rate']);
        $vatRate  = $request->quote_vat_rate ?? 0;
        $total    = $subtotal + ($subtotal * $vatRate / 100);

        $quote = $business->contracts()->create([
            'client_id'         => $request->client_id,
            'type'              => 'quote',
            'title'             => $request->title,
            'status'            => 'draft',
            'quote_status'      => 'draft',
            'value'             => $total,
            'quote_subtotal'    => $subtotal,
            'quote_vat_rate'    => $vatRate,
            'quote_total'       => $total,
            'quote_valid_until' => $request->quote_valid_until,
            'notes'             => $request->notes,
            'quote_items'       => $items,
            'contract_number'   => $business->generateContractNumber('quote'),
            'signing_token'     => Str::random(64),
            'token_expires_at'  => now()->addDays(30),
        ]);

        return response()->json(['message' => 'Quote created', 'quote' => $quote->load('client')], 201);
    }

    public function show(Business $business, Contract $contract)
    {
        if ($contract->business_id !== $business->id || $contract->type !== 'quote') {
            return response()->json(['message' => 'Quote not found'], 404);
        }
        return response()->json(['quote' => $contract->load('client')]);
    }

    public function update(Request $request, Business $business, Contract $contract)
    {
        if ($contract->business_id !== $business->id || $contract->type !== 'quote') {
            return response()->json(['message' => 'Quote not found'], 404);
        }
        if (in_array($contract->quote_status, ['accepted', 'declined'])) {
            return response()->json(['message' => 'Accepted or declined quotes cannot be edited'], 422);
        }

        $request->validate([
            'title'            => 'sometimes|string|max:255',
            'quote_valid_until'=> 'nullable|date',
            'quote_vat_rate'   => 'nullable|numeric|min:0|max:100',
            'notes'            => 'nullable|string',
            'items'            => 'sometimes|array|min:1',
            'items.*.description' => 'required_with:items|string',
            'items.*.quantity'    => 'required_with:items|numeric|min:0.01',
            'items.*.rate'        => 'required_with:items|numeric|min:0',
        ]);

        $items    = $request->items ?? $contract->quote_items;
        $subtotal = collect($items)->sum(fn ($i) => ($i['quantity'] ?? 1) * ($i['rate'] ?? 0));
        $vatRate  = $request->quote_vat_rate ?? $contract->quote_vat_rate ?? 0;
        $total    = $subtotal + ($subtotal * $vatRate / 100);

        $contract->update(array_filter([
            'title'             => $request->title ?? null,
            'quote_valid_until' => $request->quote_valid_until ?? null,
            'quote_vat_rate'    => $vatRate,
            'quote_subtotal'    => $subtotal,
            'quote_total'       => $total,
            'value'             => $total,
            'notes'             => $request->notes ?? null,
            'quote_items'       => $items,
        ], fn ($v) => $v !== null));

        return response()->json(['message' => 'Quote updated', 'quote' => $contract->load('client')]);
    }

    public function destroy(Business $business, Contract $contract)
    {
        if ($contract->business_id !== $business->id || $contract->type !== 'quote') {
            return response()->json(['message' => 'Quote not found'], 404);
        }
        $contract->delete();
        return response()->json(['message' => 'Quote deleted']);
    }

    /** Mark quote as sent and generate a shareable link */
    public function send(Business $business, Contract $contract)
    {
        if ($contract->business_id !== $business->id || $contract->type !== 'quote') {
            return response()->json(['message' => 'Quote not found'], 404);
        }

        $contract->update(['quote_status' => 'sent', 'status' => 'sent', 'sent_at' => now()]);

        $viewUrl = config('app.frontend_url') . '/quotes/' . $contract->signing_token;
        $whatsappText = "Hello, please find your quote from {$business->name}: {$viewUrl}";
        $whatsappUrl  = 'https://wa.me/?text=' . rawurlencode($whatsappText);

        return response()->json([
            'message'       => 'Quote sent',
            'view_url'      => $viewUrl,
            'whatsapp_url'  => $whatsappUrl,
        ]);
    }

    /** Client accepts or declines a quote */
    public function respond(Request $request, Business $business, Contract $contract)
    {
        if ($contract->business_id !== $business->id || $contract->type !== 'quote') {
            return response()->json(['message' => 'Quote not found'], 404);
        }

        $request->validate(['action' => 'required|in:accept,decline']);

        $contract->update([
            'quote_status'      => $request->action === 'accept' ? 'accepted' : 'declined',
            'client_response'   => $request->action === 'accept' ? 'accepted' : 'rejected',
            'client_responded_at'=> now(),
        ]);

        return response()->json(['message' => 'Response recorded', 'quote' => $contract]);
    }

    /** Convert an accepted quote to an invoice */
    public function convertToInvoice(Business $business, Contract $contract, InvoiceService $service)
    {
        if ($contract->business_id !== $business->id || $contract->type !== 'quote') {
            return response()->json(['message' => 'Quote not found'], 404);
        }
        if ($contract->quote_status !== 'accepted') {
            return response()->json(['message' => 'Only accepted quotes can be converted to invoices'], 422);
        }

        $items = $contract->quote_items ?? [];
        if (empty($items)) {
            return response()->json(['message' => 'Quote has no line items to convert'], 422);
        }

        $today   = now();
        $dueDate = $today->copy()->addDays(30);

        $data = [
            'client_id'   => $contract->client_id,
            'contract_id' => $contract->id,
            'issue_date'  => $today->toDateString(),
            'due_date'    => $dueDate->toDateString(),
            'currency'    => $business->currency ?? 'GHS',
            'vat_rate'    => $contract->quote_vat_rate ?? 0,
            'notes'       => $contract->notes,
            'items'       => collect($items)->map(fn ($i) => [
                'description' => $i['description'],
                'quantity'    => $i['quantity'] ?? 1,
                'rate'        => $i['rate'] ?? 0,
            ])->toArray(),
        ];

        $invoice = $service->createDraft($business, $data);

        // Mark quote as converted
        $contract->update(['quote_status' => 'invoiced']);

        return response()->json([
            'message' => 'Invoice created from quote',
            'invoice' => $invoice,
        ], 201);
    }
}
