<?php

namespace App\Http\Resources\V1;

use App\Services\Cart\CartService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The cart isn't a model — this wraps the CartService for the current viewer.
 *
 * @property CartService $resource
 */
class CartResource extends JsonResource
{
    public function __construct(CartService $cart)
    {
        parent::__construct($cart);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $items = $this->resource->items();

        return [
            'items' => $items->map(fn (array $line) => [
                'variant' => ProductVariantResource::make($line['variant']),
                'qty' => $line['qty'],
                'line_total_cents' => $line['line_total_cents'],
                'line_total' => money($line['line_total_cents']),
            ])->values(),
            'count' => $items->sum('qty'),
            'total_cents' => $total = $items->sum('line_total_cents'),
            'total' => money($total),
            'currency' => 'USD',
        ];
    }
}
