<?php

namespace App\Services\Catalog;

use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * One entry point for the storefront and the API: text search (Scout) plus facets
 * (Product::filter), paginated. Keeps both surfaces returning the same results.
 */
class ProductSearch
{
    /**
     * @param  array{q?: ?string, category?: ?string, min_price?: ?int, max_price?: ?int, in_stock?: bool, min_rating?: ?int, sort?: ?string}  $filters
     * @return LengthAwarePaginator<int, Product>
     */
    public function paginate(array $filters, int $perPage = 12): LengthAwarePaginator
    {
        $constrain = fn (Builder $query): Builder => $this->constrain($query, $filters);

        $term = trim((string) ($filters['q'] ?? ''));

        if ($term === '') {
            return $constrain(Product::query())->paginate($perPage)->withQueryString();
        }

        // Scout matches the text. With the database driver the callback is applied to that same
        // query, so visibility, facets and sorting run as one SQL statement and the page total is
        // exact. (On Meilisearch the callback only shapes hydration — facets would move to
        // engine-side filterable attributes.)
        return Product::search($term)
            ->query($constrain)
            ->paginate($perPage)
            ->withQueryString()
            ->appends('query', null); // Scout appends its own `query=`; ours is `q` (already in the query string)
    }

    /**
     * Visibility, eager loads, rating aggregates and facets — identical for both branches.
     *
     * @param  Builder<Product>  $query
     * @param  array{q?: ?string, category?: ?string, min_price?: ?int, max_price?: ?int, in_stock?: bool, min_rating?: ?int, sort?: ?string}  $filters
     * @return Builder<Product>
     */
    private function constrain(Builder $query, array $filters): Builder
    {
        return $query
            ->published()
            ->with(['store', 'variants', 'primaryImage'])
            ->withRating()
            ->filter($filters);
    }
}
