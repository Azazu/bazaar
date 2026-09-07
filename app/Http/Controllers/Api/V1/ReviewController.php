<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreReviewRequest;
use App\Http\Resources\V1\ReviewResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReviewController extends Controller
{
    /** Approved reviews of a published product. */
    public function index(Product $product): AnonymousResourceCollection
    {
        abort_unless($product->status === ProductStatus::Published, 404);

        $reviews = $product->reviews()
            ->approved()
            ->with('user')
            ->latest()
            ->paginate(15);

        return ReviewResource::collection($reviews);
    }

    /** Submit a review; it stays hidden until an admin approves it. */
    public function store(StoreReviewRequest $request, Product $product): JsonResponse
    {
        $user = $request->user() ?? abort(401);

        $review = $product->reviews()->create([
            'user_id' => $user->id,
            'rating' => $request->integer('rating'),
            'body' => $request->input('body'),
            'approved' => false,
        ]);

        return ReviewResource::make($review->load('user'))
            ->response()
            ->setStatusCode(201);
    }
}
