<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function search(Request $request, Business $business)
    {
        $request->validate(['q' => 'required|string|min:2']);
        $q = $request->q;

        $invoices = $business->invoices()->with('client:id,name')
            ->where(function ($query) use ($q) {
                $query->where('invoice_number', 'like', "%{$q}%")
                    ->orWhereHas('client', fn($qr) => $qr->where('name', 'like', "%{$q}%"));
            })->take(5)->get()->map(fn($i) => [
                'type' => 'invoice', 'id' => $i->id, 'title' => $i->invoice_number,
                'subtitle' => $i->client->name ?? '', 'status' => $i->status,
                'url' => "/dashboard/invoices/{$i->id}",
            ]);

        $clients = $business->clients()
            ->where(function ($query) use ($q) {
                $query->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%");
            })->take(5)->get()->map(fn($c) => [
                'type' => 'client', 'id' => $c->id, 'title' => $c->name,
                'subtitle' => $c->email, 'status' => $c->status,
                'url' => "/dashboard/clients/{$c->id}",
            ]);

        $contracts = $business->contracts()->with('client:id,name')
            ->where(function ($query) use ($q) {
                $query->where('title', 'like', "%{$q}%")
                    ->orWhere('contract_number', 'like', "%{$q}%")
                    ->orWhereHas('client', fn($qr) => $qr->where('name', 'like', "%{$q}%"));
            })->take(5)->get()->map(fn($c) => [
                'type' => 'contract', 'id' => $c->id, 'title' => $c->title,
                'subtitle' => $c->contract_number . ' • ' . ($c->client->name ?? ''),
                'status' => $c->status, 'url' => "/dashboard/contracts/{$c->id}",
            ]);

        $expenses = $business->expenses()
            ->where(function ($query) use ($q) {
                $query->where('description', 'like', "%{$q}%")->orWhere('vendor', 'like', "%{$q}%");
            })->take(5)->get()->map(fn($e) => [
                'type' => 'expense', 'id' => $e->id, 'title' => $e->description,
                'subtitle' => ($business->currency ?? 'GHS') . " " . number_format($e->amount, 2) . " • {$e->category}",
                'status' => $e->status, 'url' => "/dashboard/expenses",
            ]);

        $results = $invoices->merge($clients)->merge($contracts)->merge($expenses);

        return response()->json([
            'results' => $results,
            'total' => $results->count(),
            'query' => $q,
        ]);
    }
}
