<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ProductVariant;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AddCartItemRequest extends FormRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'qty' => ['sometimes', 'integer', 'min:1', 'max:99'],
        ];
    }

    /**
     * Beyond "exists": the variant must be sellable — its product published and
     * at least one unit in stock (mirrors the storefront's "Add to cart" button).
     *
     * @return array<int, Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->has('variant_id')) {
                    return;
                }

                $variant = ProductVariant::with('product')->findOrFail($this->integer('variant_id'));

                if (! $variant->product?->isVisible()) {
                    $validator->errors()->add('variant_id', __('This product is not available.'));
                } elseif ($variant->stock < 1) {
                    $validator->errors()->add('variant_id', __('This variant is out of stock.'));
                }
            },
        ];
    }
}
