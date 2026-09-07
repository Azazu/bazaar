<?php

namespace App\Http\Resources\V1;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Product */
class ProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'price_cents' => $this->price_cents,
            'currency' => $this->currency,
            'price' => money($this->price_cents, $this->currency),
            // Cheapest/dearest variant — present on lists (withPriceRange()).
            'price_range' => $this->whenHas('min_price_cents', fn () => [
                'min_cents' => (int) $this->min_price_cents,
                'max_cents' => (int) $this->max_price_cents,
                'label' => $this->priceLabel(),
            ]),
            // Present only when the query used Product::withRating() — avoids an N+1 on lists.
            'rating' => $this->whenHas('reviews_avg_rating', fn () => [
                'average' => round((float) $this->reviews_avg_rating, 1),
                'count' => (int) $this->reviews_count,
            ]),
            // Primary image for lists (eager-loaded); the full gallery only on the product itself.
            'image' => $this->whenLoaded('primaryImage', fn () => $this->primaryImage?->url('card')),
            'images' => ProductImageResource::collection($this->whenLoaded('images')),
            'store' => StoreResource::make($this->whenLoaded('store')),
            'categories' => CategoryResource::collection($this->whenLoaded('categories')),
            'variants' => ProductVariantResource::collection($this->whenLoaded('variants')),
            'created_at' => $this->created_at,
        ];
    }
}
