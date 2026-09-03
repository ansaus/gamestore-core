<?php

namespace App\Http\Requests;

use App\Domain\Catalog\CatalogQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CatalogRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'type' => ['nullable', Rule::in(['topup', 'key', 'subscription', 'giftcard'])],
            // Курсор keyset-пагинации: sku последней строки предыдущей страницы.
            'after' => ['nullable', 'string', 'max:200'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.CatalogQuery::MAX_LIMIT],
        ];
    }
}
