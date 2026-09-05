<?php

namespace App\Domain\Order;

enum OrderStatus: string
{
    case Created = 'created';
    case Paid = 'paid';
    case Delivering = 'delivering';
    case Delivered = 'delivered';
    case PaymentFailed = 'payment_failed';
    case OutOfStock = 'out_of_stock';
    case DeliveryFailed = 'delivery_failed';

    /**
     * Таблица разрешённых переходов — единственный источник правды.
     *
     * Два места требуют пояснения:
     *  - payment_failed → paid: поздний `paid` побеждает. Деньги реально пришли,
     *    и отказать клиенту из-за порядка доставки вебхуков нельзя.
     *  - из delivered не ведёт ничего: выдача необратима (I5).
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Created => [self::Paid, self::PaymentFailed],
            self::Paid => [self::Delivering],
            self::Delivering => [self::Delivered, self::OutOfStock, self::DeliveryFailed],
            self::OutOfStock, self::DeliveryFailed => [self::Delivering],
            self::PaymentFailed => [self::Paid],
            self::Delivered => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /** Ничем не откатывается. */
    public function isTerminal(): bool
    {
        return $this === self::Delivered;
    }

    /** Оплачено, но не выдано — фоновое доведение обязано вернуться к этим заказам. */
    public function isRecoverable(): bool
    {
        return in_array($this, [self::OutOfStock, self::DeliveryFailed], true);
    }

    /** Деньги за заказ подтверждены. */
    public function isPaidSide(): bool
    {
        return in_array($this, [
            self::Paid, self::Delivering, self::Delivered,
            self::OutOfStock, self::DeliveryFailed,
        ], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
