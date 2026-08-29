<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Coupon;
use App\Services\Cart\CartService;
use App\Services\Checkout\CheckoutService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CheckoutRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'shipping_address' => ['required', 'array'],
            'shipping_address.name' => ['required', 'string', 'max:255'],
            'shipping_address.line1' => ['required', 'string', 'max:255'],
            'shipping_address.city' => ['required', 'string', 'max:255'],
            'shipping_address.postcode' => ['required', 'string', 'max:20'],
            'shipping_address.country' => ['required', 'string', 'size:2'],
            'shipping_method' => ['required', Rule::in(array_keys(CheckoutService::SHIPPING_RATES))],
            'coupon_code' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * Rules that need the viewer's cart: it must not be empty, and a coupon (if given)
     * must exist and be valid for this subtotal — an invalid one is a hard error here,
     * never a silently dropped discount.
     *
     * @return array<int, Closure>
     */
    public function after(CartService $cart): array
    {
        return [
            function (Validator $validator) use ($cart) {
                if ($cart->items()->isEmpty()) {
                    $validator->errors()->add('cart', __('Your cart is empty.'));

                    return;
                }

                if ($this->filled('coupon_code') && ! $this->coupon()?->isValidFor($cart->total())) {
                    $validator->errors()->add('coupon_code', __('Invalid or expired coupon.'));
                }
            },
        ];
    }

    /** @return array<string, string> */
    public function shippingAddress(): array
    {
        $address = $this->validated('shipping_address');
        $address['country'] = strtoupper($address['country']);

        return $address;
    }

    public function shippingMethod(): string
    {
        return $this->validated('shipping_method');
    }

    public function coupon(): ?Coupon
    {
        if (! $this->filled('coupon_code')) {
            return null;
        }

        return Coupon::where('code', strtoupper(trim($this->input('coupon_code'))))->first();
    }
}
