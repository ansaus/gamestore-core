<?php

namespace App\Http\Controllers\Api;

use App\Domain\Payment\PaymentEventData;
use App\Domain\Payment\PaymentEventProcessor;
use App\Http\Controllers\Controller;
use App\Http\Requests\PaymentWebhookRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class PaymentWebhookController extends Controller
{
    /**
     * Приём платёжного события.
     *
     * Коды ответа — часть контракта с платёжкой:
     *   200 — событие принято ИЛИ распознано как дубль (повторять не нужно);
     *   400 — payload невалиден (см. PaymentWebhookRequest);
     *   5xx — реальный сбой БД, платёжка обязана повторить. Поэтому исключения
     *         здесь сознательно не гасятся.
     */
    public function store(PaymentWebhookRequest $request, PaymentEventProcessor $processor): JsonResponse
    {
        $data = PaymentEventData::fromPayload($request->validated(), $request->all());

        Log::info('payment.received', [
            'event' => 'payment.received',
            'event_id' => $data->eventId,
            'order_id' => $data->orderId,
            'status' => $data->status->value,
        ]);

        $result = $processor->process($data);

        return response()->json([
            'result' => $result->value,
            'order_id' => $data->orderId,
        ]);
    }
}
