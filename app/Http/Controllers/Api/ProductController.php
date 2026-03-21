<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request, Business $business)
    {
        $query = $business->products()->where('is_active', true);

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('description', 'like', "%{$request->search}%");
            });
        }

        if ($request->filled('category') && $request->category !== 'all') {
            $query->where('category', $request->category);
        }

        $products = $query->orderByDesc('usage_count')->orderBy('name')
                          ->paginate($request->per_page ?? 50);

        $categories = $business->products()
            ->whereNotNull('category')
            ->where('is_active', true)
            ->distinct()
            ->pluck('category');

        return response()->json([
            'products'   => $products,
            'categories' => $categories,
        ]);
    }

    public function store(Request $request, Business $business)
    {
        $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'price'       => 'required|numeric|min:0',
            'unit'        => 'nullable|string|max:50',
            'currency'    => 'nullable|string|max:10',
            'category'    => 'nullable|string|max:100',
        ]);

        $product = $business->products()->create([
            'name'        => $request->name,
            'description' => $request->description,
            'price'       => $request->price,
            'unit'        => $request->unit,
            'currency'    => $request->currency ?? $business->currency ?? 'GHS',
            'category'    => $request->category,
            'is_active'   => true,
        ]);

        return response()->json(['message' => 'Product saved', 'product' => $product], 201);
    }

    public function show(Business $business, Product $product)
    {
        if ($product->business_id !== $business->id) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return response()->json(['product' => $product]);
    }

    public function update(Request $request, Business $business, Product $product)
    {
        if ($product->business_id !== $business->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $request->validate([
            'name'        => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'price'       => 'sometimes|numeric|min:0',
            'unit'        => 'nullable|string|max:50',
            'currency'    => 'nullable|string|max:10',
            'category'    => 'nullable|string|max:100',
            'is_active'   => 'sometimes|boolean',
        ]);

        $product->update($request->only([
            'name', 'description', 'price', 'unit', 'currency', 'category', 'is_active',
        ]));

        return response()->json(['message' => 'Product updated', 'product' => $product]);
    }

    public function destroy(Business $business, Product $product)
    {
        if ($product->business_id !== $business->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $product->update(['is_active' => false]); // soft-hide instead of hard delete
        return response()->json(['message' => 'Product removed from catalogue']);
    }

    /** Called when a product is added to an invoice — increments usage counter */
    public function recordUsage(Business $business, Product $product)
    {
        if ($product->business_id !== $business->id) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $product->incrementUsage();
        return response()->json(['message' => 'Usage recorded']);
    }
}
