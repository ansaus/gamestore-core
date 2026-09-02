<?php

use App\Domain\Order\OrderStatus;

it('разрешает основной путь заказа', function () {
    expect(OrderStatus::Created->canTransitionTo(OrderStatus::Paid))->toBeTrue()
        ->and(OrderStatus::Paid->canTransitionTo(OrderStatus::Delivering))->toBeTrue()
        ->and(OrderStatus::Delivering->canTransitionTo(OrderStatus::Delivered))->toBeTrue();
});

it('не выпускает заказ из delivered', function () {
    expect(OrderStatus::Delivered->allowedTransitions())->toBeEmpty()
        ->and(OrderStatus::Delivered->isTerminal())->toBeTrue();
});

it('пропускает поздний paid поверх payment_failed', function () {
    expect(OrderStatus::PaymentFailed->canTransitionTo(OrderStatus::Paid))->toBeTrue();
});

it('запрещает прыжок через выдачу', function () {
    expect(OrderStatus::Created->canTransitionTo(OrderStatus::Delivered))->toBeFalse()
        ->and(OrderStatus::Created->canTransitionTo(OrderStatus::Delivering))->toBeFalse();
});

it('считает восстановимыми только out_of_stock и delivery_failed', function () {
    $recoverable = array_filter(OrderStatus::cases(), fn (OrderStatus $s) => $s->isRecoverable());

    expect(array_values($recoverable))->toBe([OrderStatus::OutOfStock, OrderStatus::DeliveryFailed]);
});

it('возвращает из out_of_stock и delivery_failed только в delivering', function () {
    expect(OrderStatus::OutOfStock->allowedTransitions())->toBe([OrderStatus::Delivering])
        ->and(OrderStatus::DeliveryFailed->allowedTransitions())->toBe([OrderStatus::Delivering]);
});
