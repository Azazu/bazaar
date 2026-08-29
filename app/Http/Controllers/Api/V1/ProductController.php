<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductIndexRequest;
use App\Http\Resources\V1\ProductResource;
use App\Models\Product;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Public, read-only catalog. Only published products are ever visible here. */
class ProductController extends Controller
{
    public function index(ProductIndexRequest $request): AnonymousResourceCollection
    {
        $products = Product::query()
            ->published()
            ->with(['store', 'variants'])
            ->withRating()
            ->filter($request->filters())
            ->paginate($request->perPage())
            ->withQueryString();

        return ProductResource::collection($products);
    }

    public function show(string $slug): ProductResource
    {
        // Resolved by hand rather than route-model binding so drafts are a 404, not a leak.
        $product = Product::query()
            ->published()
            ->with(['store', 'variants', 'categories'])
            ->withRating()
            ->where('slug', $slug)
            ->firstOrFail();

        return ProductResource::make($product);
    }
}
