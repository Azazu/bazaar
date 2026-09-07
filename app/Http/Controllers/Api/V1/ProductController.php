<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductIndexRequest;
use App\Http\Resources\V1\ProductResource;
use App\Models\Product;
use App\Services\Catalog\ProductSearch;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Public, read-only catalog. Only published products are ever visible here. */
class ProductController extends Controller
{
    /** Search (`q`) + facets, shared with the storefront via ProductSearch. */
    public function index(ProductIndexRequest $request, ProductSearch $search): AnonymousResourceCollection
    {
        return ProductResource::collection(
            $search->paginate($request->filters(), $request->perPage()),
        );
    }

    public function show(string $slug): ProductResource
    {
        // Resolved by hand rather than route-model binding so drafts are a 404, not a leak.
        $product = Product::query()
            ->published()
            ->with(['store', 'variants', 'categories', 'images'])
            ->withRating()
            ->where('slug', $slug)
            ->firstOrFail();

        return ProductResource::make($product);
    }
}
