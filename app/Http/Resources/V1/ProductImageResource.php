<?php

namespace App\Http\Resources\V1;

use App\Models\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductImage */
class ProductImageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'position' => $this->position,
            'large' => $this->url('large'),
            'card' => $this->url('card'),
            'thumb' => $this->url('thumb'),
        ];
    }
}
