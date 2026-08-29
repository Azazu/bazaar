<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Query-string filters for the public catalog. */
class ProductIndexRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'exists:categories,slug'],
            'min_price' => ['nullable', 'integer', 'min:0'],
            // gte: only when a lower bound was actually sent — against a missing field the rule
            // compares with null and rejects every max_price.
            'max_price' => ['nullable', 'integer', 'min:0', Rule::when($this->filled('min_price'), ['gte:min_price'])],
            'in_stock' => ['nullable', 'boolean'],
            'min_rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'sort' => ['nullable', Rule::in(['newest', 'price_asc', 'price_desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    /**
     * Validated filters, normalized for ProductSearch / Product::filter().
     *
     * @return array{q: ?string, category: ?string, min_price: ?int, max_price: ?int, in_stock: bool, min_rating: ?int, sort: string}
     */
    public function filters(): array
    {
        return [
            'q' => $this->validated('q'),
            'category' => $this->validated('category'),
            'min_price' => $this->filled('min_price') ? $this->integer('min_price') : null,
            'max_price' => $this->filled('max_price') ? $this->integer('max_price') : null,
            'in_stock' => $this->boolean('in_stock'),
            'min_rating' => $this->filled('min_rating') ? $this->integer('min_rating') : null,
            'sort' => $this->validated('sort') ?? 'newest',
        ];
    }

    public function perPage(): int
    {
        return $this->integer('per_page', 12);
    }
}
