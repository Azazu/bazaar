<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AddCartItemRequest;
use App\Http\Requests\Api\V1\UpdateCartItemRequest;
use App\Http\Resources\V1\CartResource;
use App\Models\ProductVariant;
use App\Services\Cart\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * The authenticated user's account cart. Every mutation returns the full cart so a
 * client never has to re-fetch after a change.
 */
class CartController extends Controller
{
    public function __construct(private readonly CartService $cart) {}

    public function show(): CartResource
    {
        return new CartResource($this->cart);
    }

    public function store(AddCartItemRequest $request): JsonResponse
    {
        $this->cart->add($request->integer('variant_id'), $request->integer('qty', 1));

        return (new CartResource($this->cart))->response()->setStatusCode(201);
    }

    public function update(UpdateCartItemRequest $request, ProductVariant $variant): CartResource
    {
        $this->cart->update($variant->id, $request->integer('qty'));

        return new CartResource($this->cart);
    }

    public function destroy(ProductVariant $variant): CartResource
    {
        $this->cart->remove($variant->id);

        return new CartResource($this->cart);
    }

    public function clear(): Response
    {
        $this->cart->clear();

        return response()->noContent();
    }
}
