<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Product;
use App\Models\Review;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreReviewRequest extends FormRequest
{
    /** Only buyers of the product may review it (ReviewPolicy). */
    public function authorize(): bool
    {
        return $this->user()?->can('create', [Review::class, $this->product()]) ?? false;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'body' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * One review per buyer per product (also enforced by a unique index — this turns
     * the DB error into a friendly 422).
     *
     * @return array<int, Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $alreadyReviewed = $this->product()->reviews()
                    ->where('user_id', $this->user()?->id)
                    ->exists();

                if ($alreadyReviewed) {
                    $validator->errors()->add('rating', __('You have already reviewed this product.'));
                }
            },
        ];
    }

    private function product(): Product
    {
        $product = $this->route('product');

        return $product instanceof Product ? $product : abort(404);
    }
}
