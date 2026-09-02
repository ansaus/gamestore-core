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
}
