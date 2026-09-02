<?php

namespace App\Http\Requests;

use App\Domain\Payment\PaymentStatus;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class PaymentWebhookRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'event_id' => ['required', 'string', 'max:200'],
            'order_id' => ['required', 'string', 'max:200'],
            'status' => ['required', Rule::in(PaymentStatus::values())],
            // Сумма и валюта опциональны: если платёжка их не прислала,
            // отвечать 400 нельзя — она будет долбиться повторами вечно.
            'amount' => ['nullable', 'numeric'],
            'currency' => ['nullable', 'string', 'size:3'],
            'created_at' => ['nullable', 'date'],
        ];
    }

    /**
     * Невалидный payload — это 400, а не 422: по контракту вебхука
     * 4xx означает «не присылай это снова», и различать оттенки платёжке незачем.
     */
    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(response()->json([
            'result' => 'invalid_payload',
            'errors' => $validator->errors()->toArray(),
        ], 400));
    }
}
