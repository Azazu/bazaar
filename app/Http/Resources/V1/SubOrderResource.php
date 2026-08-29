<?php

namespace App\Http\Resources\V1;

use App\Models\SubOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SubOrder */
class SubOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->getValue(),
            'status_label' => $this->status->label(),
            'subtotal_cents' => $this->subtotal_cents,
            'subtotal' => money($this->subtotal_cents),
            'store' => StoreResource::make($this->whenLoaded('store')),
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
