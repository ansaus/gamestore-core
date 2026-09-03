<?php

namespace App\Domain\Payment;

use Carbon\CarbonImmutable;

final readonly class PaymentEventData
{
    /**
     * @param  array<string, mixed>  $payload  исходное тело вебхука, как пришло
     */
    public function __construct(
        public string $eventId,
        public string $orderId,
        public PaymentStatus $status,
        public ?string $amount,
        public ?string $currency,
        public ?CarbonImmutable $occurredAt,
        public array $payload,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  валидированные поля
     * @param  array<string, mixed>|null  $raw  тело запроса как пришло; оно и уезжает в payload
     */
    public static function fromPayload(array $payload, ?array $raw = null): self
    {
        return new self(
            eventId: (string) $payload['event_id'],
            orderId: (string) $payload['order_id'],
            status: PaymentStatus::from((string) $payload['status']),
            amount: isset($payload['amount']) ? (string) $payload['amount'] : null,
            currency: isset($payload['currency']) ? strtoupper((string) $payload['currency']) : null,
            occurredAt: isset($payload['created_at'])
                ? CarbonImmutable::parse((string) $payload['created_at'])
                : null,
            payload: $raw ?? $payload,
        );
    }

    /**
     * Событие, уже лежащее в журнале. Нужно подхвату сирот: там событие
     * пришло раньше заказа и применяется вторым заходом, из БД, а не из HTTP.
     */
    public static function fromEvent(PaymentEvent $event): self
    {
        return new self(
            eventId: (string) $event->event_id,
            orderId: (string) $event->order_id,
            status: $event->status,
            amount: $event->amount !== null ? (string) $event->amount : null,
            currency: $event->currency !== null ? strtoupper(trim((string) $event->currency)) : null,
            occurredAt: $event->occurred_at?->toImmutable(),
            payload: $event->payload ?? [],
        );
    }
}
