<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ProductVariant;
use App\Services\Cart\CartService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateCartItemRequest extends FormRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        // Removal is DELETE /cart/items/{variant}, so a quantity here is always >= 1.
        return [
            'qty' => ['required', 'integer', 'min:1', 'max:99'],
        ];
    }

    /**
     * PATCH changes a line that is already in the cart; it must not become a second way of
     * adding one, which would skip the sellability checks of POST /cart/items.
     *
     * @return array<int, Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $variant = $this->route('variant');

                if ($variant instanceof ProductVariant && ! app(CartService::class)->has($variant->id)) {
                    $validator->errors()->add('variant', __('This variant is not in your cart.'));
                }
            },
        ];
    }
}
