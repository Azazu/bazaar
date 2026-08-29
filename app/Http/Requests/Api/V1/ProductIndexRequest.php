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
            'category' => ['nullable', 'string', 'exists:categories,slug'],
            'min_price' => ['nullable', 'integer', 'min:0'],
            'max_price' => ['nullable', 'integer', 'min:0', 'gte:min_price'],
            'in_stock' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(['newest', 'price_asc', 'price_desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    /**
     * Validated filters, normalized for Product::filter().
     *
     * @return array{category: ?string, min_price: ?int, max_price: ?int, in_stock: bool, sort: string}
     */
    public function filters(): array
    {
        return [
            'category' => $this->validated('category'),
            'min_price' => $this->filled('min_price') ? $this->integer('min_price') : null,
            'max_price' => $this->filled('max_price') ? $this->integer('max_price') : null,
            'in_stock' => $this->boolean('in_stock'),
            'sort' => $this->validated('sort') ?? 'newest',
        ];
    }

    public function perPage(): int
    {
        return $this->integer('per_page', 12);
    }
}
