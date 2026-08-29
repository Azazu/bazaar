<?php

namespace App\Http\Resources\V1;

use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductVariant */
class ProductVariantResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'price_cents' => $this->price_cents,
            'price' => money($this->price_cents),
            'stock' => $this->stock,
            'in_stock' => $this->stock > 0,
            'product' => ProductResource::make($this->whenLoaded('product')),
        ];
    }
}
