<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

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
}
