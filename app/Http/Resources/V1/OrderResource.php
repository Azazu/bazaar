<?php

namespace App\Http\Resources\V1;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->getValue(),
            'status_label' => $this->status->label(),
            'currency' => $this->currency,
            'subtotal_cents' => $this->subtotal_cents,
            'shipping_cents' => $this->shipping_cents,
            'discount_cents' => $this->discount_cents,
            'total_cents' => $this->total_cents,
            'total' => money($this->total_cents, $this->currency),
            'coupon_code' => $this->whenLoaded('coupon', fn () => $this->coupon?->code),
            'shipping_address' => $this->shipping_address,
            'shipping_method' => $this->shipping_method,
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'sub_orders' => SubOrderResource::collection($this->whenLoaded('subOrders')),
            'created_at' => $this->created_at,
        ];
    }
}
