<?php

namespace Paymenter\Extensions\Others\MobileAPIBridge\Http\Controllers;

use App\Http\Resources\CategoryResource;
use App\Http\Resources\ProductResource;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;
use Paymenter\Extensions\Others\MobileAPIBridge\Support\PaginationClamp;

/**
 * Public catalog browsing — categories and products/plans, exactly the
 * same underlying models the admin API and the web storefront use. No
 * customer session required (catalog is public before checkout), matching
 * how Paymenter's own storefront works. Only non-`hidden` items are ever
 * returned — verified against the real `products` table schema
 * (`hidden` boolean, added in migration
 * 2025_03_11_205629_add_hidden_to_products.php), never an invented
 * `enabled` field.
 */
class CatalogController
{
    public function categories(Request $request)
    {
        $categories = Category::whereNull('parent_id')
            // CategoryResource serializes the loaded products relationship.
            // Filter it here as well as in products()/product(), otherwise a
            // public category response can disclose hidden product metadata.
            ->with(['products' => fn ($query) => $query->where('hidden', false)])
            ->orderBy('sort')
            ->paginate(PaginationClamp::perPage($request), page: PaginationClamp::page($request));

        return CategoryResource::collection($categories);
    }

    public function products(Request $request)
    {
        $query = Product::query()->where('hidden', false);

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->integer('category_id'));
        }

        $products = $query->with('category')
            ->paginate(PaginationClamp::perPage($request), page: PaginationClamp::page($request));

        return ProductResource::collection($products);
    }

    public function product(Product $product)
    {
        abort_if($product->hidden, 404);

        $currency = session('currency', config('settings.default_currency'));

        return response()->json([
            'data' => [
                'id' => (string) $product->id,
                'type' => 'products',
                'attributes' => [
                    'name' => $product->name,
                    'description' => $product->description,
                    'image' => $product->image,
                    'stock' => $product->stock,
                    'hidden' => $product->hidden,
                ],
                'plans' => $product->plans->map(function ($plan) use ($currency) {
                    $price = $plan->price($currency);

                    return [
                        'id' => (string) $plan->id,
                        'name' => $plan->name,
                        'type' => $plan->type,
                        'billing_period' => $plan->billing_period,
                        'billing_unit' => $plan->billing_unit,
                        'price' => $price->price !== null ? (string) $price->price : null,
                        'setup_fee' => $price->setup_fee !== null ? (string) $price->setup_fee : null,
                        'currency_code' => $price->currency?->code,
                        'available' => $price->price !== null,
                    ];
                }),
            ],
        ]);
    }
}

