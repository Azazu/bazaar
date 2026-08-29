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
            // Present only when the query used Product::withRating() — avoids an N+1 on lists.
            'rating' => $this->whenHas('reviews_avg_rating', fn () => [
                'average' => round((float) $this->reviews_avg_rating, 1),
                'count' => (int) $this->reviews_count,
            ]),
            'store' => StoreResource::make($this->whenLoaded('store')),
            'categories' => CategoryResource::collection($this->whenLoaded('categories')),
            'variants' => ProductVariantResource::collection($this->whenLoaded('variants')),
            'created_at' => $this->created_at,
        ];
    }
}
