<?php

namespace App\Http\Resources\V1;

use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Snapshot line: title/name/price as they were at purchase time.
 *
 * @mixin OrderItem
 */
class OrderItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_variant_id' => $this->product_variant_id,
            'product_title' => $this->product_title,
            'variant_name' => $this->variant_name,
            'unit_price_cents' => $this->unit_price_cents,
            'unit_price' => money($this->unit_price_cents),
            'qty' => $this->qty,
            'line_total_cents' => $this->unit_price_cents * $this->qty,
        ];
    }
}
