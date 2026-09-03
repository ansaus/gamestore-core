<?php

namespace App\Domain\Payment;

use App\Domain\Ledger\Ledger;
use App\Domain\Order\Order;
use App\Domain\Order\OrderStatus;
use App\Domain\Order\OrderTransitions;
use App\Jobs\DeliverOrderJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Применение платёжного события.
 *
 * Порядок шагов важен и повторяет SPEC §5:
 *   1. INSERT ... ON CONFLICT (event_id) DO NOTHING — единственная точка
 *      дедупликации. Не «SELECT, если нет — INSERT»: это гонка.
 *   2. SELECT ... FOR UPDATE по строке заказа — сериализует параллельные
 *      вебхуки по одному заказу.
 *   3. Применение статуса + проводки.
 * Внутри транзакции нет ни одного внешнего вызова (I7); выдача уходит в
 * очередь строго afterCommit.
 *
 * Второй вход в ту же логику — applyPending(): события, пришедшие раньше
 * заказа, применяются позже и уже из журнала, а не из HTTP.
 */
class PaymentEventProcessor
{
    public function __construct(
        private readonly OrderTransitions $transitions,
        private readonly Ledger $ledger,
    ) {}

    public function process(PaymentEventData $data): PaymentApplyResult
    {
        return DB::transaction(function () use ($data): PaymentApplyResult {
            if (! $this->record($data)) {
                Log::info('payment.duplicate', [
                    'event' => 'payment.duplicate',
                    'event_id' => $data->eventId,
                    'order_id' => $data->orderId,
                    'outcome' => PaymentApplyResult::Duplicate->value,
                ]);

                return PaymentApplyResult::Duplicate;
            }

            $order = Order::where('id', $data->orderId)->lockForUpdate()->first();

            if ($order === null) {
                // Событие раньше заказа. Сохраняем и оставляем applied_at = null:
                // ровно по этому признаку его найдёт applyPending().
                $this->finish($data->eventId, PaymentApplyResult::Orphan, applied: false);

                Log::info('payment.orphan', [
                    'event' => 'payment.orphan',
                    'event_id' => $data->eventId,
                    'order_id' => $data->orderId,
                    'outcome' => PaymentApplyResult::Orphan->value,
                ]);

                return PaymentApplyResult::Orphan;
            }

            return $this->applyToOrder($data, $order, source: 'webhook');
        });
    }

    /**
     * Подхват событий, пришедших раньше заказа (SPEC §5.3).
     *
     * Вызывается из двух мест: при создании заказа — в той же транзакции, что
     * и вставка; и фоновой задачей ReconcileOrphanEvents — на случай, если
     * заказ появился мимо этого пути.
     *
     * Порядок применения — по occurred_at из payload, при равенстве по
     * received_at. Событие без occurred_at считается случившимся тогда, когда
     * мы его получили: другой отметки времени у нас про него нет.
     *
     * @return int сколько событий применено
     */
    public function applyPending(string $orderId): int
    {
        return DB::transaction(function () use ($orderId): int {
            // Тот же порядок блокировок, что и в process(): сначала заказ.
            // Иначе два подхвата по одному заказу встанут в дедлок.
            $order = Order::where('id', $orderId)->lockForUpdate()->first();

            if ($order === null) {
                return 0;
            }

            $pending = PaymentEvent::where('order_id', $orderId)
                ->whereNull('applied_at')
                ->orderByRaw('coalesce(occurred_at, received_at) asc, received_at asc')
                ->lockForUpdate()
                ->get();

            foreach ($pending as $event) {
                // Каждое следующее событие смотрит на статус, оставленный
                // предыдущим: paid поверх payment_failed должен видеть отказ.
                $this->applyToOrder(PaymentEventData::fromEvent($event), $order->refresh(), source: 'orphan_pickup');
            }

            if ($pending->isNotEmpty()) {
                Log::info('payment.orphan_resolved', [
                    'event' => 'payment.orphan_resolved',
                    'order_id' => $orderId,
                    'outcome' => 'applied',
                    'count' => $pending->count(),
                ]);
            }

            return $pending->count();
        });
    }

    /** Применение события к найденному и уже заблокированному заказу. */
    private function applyToOrder(PaymentEventData $data, Order $order, string $source): PaymentApplyResult
    {
        $mismatch = $this->amountMismatches($data, $order);
        $result = $this->apply($data, $order);

        $this->finish($data->eventId, $result, applied: true, mismatch: $mismatch);

        Log::info('payment.applied', [
            'event' => 'payment.applied',
            'event_id' => $data->eventId,
            'order_id' => $order->id,
            'outcome' => $result->value,
            'amount_mismatch' => $mismatch,
            'source' => $source,
        ]);

        return $result;
    }

    /** @return bool false, если событие с таким event_id уже принято */
    private function record(PaymentEventData $data): bool
    {
        $inserted = DB::table('payment_events')->insertOrIgnore([[
            'event_id' => $data->eventId,
            'order_id' => $data->orderId,
            'status' => $data->status->value,
            'amount' => $data->amount,
            'currency' => $data->currency,
            'occurred_at' => $data->occurredAt,
            'received_at' => now(),
            'payload' => json_encode($data->payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]]);

        return $inserted > 0;
    }

    private function apply(PaymentEventData $data, Order $order): PaymentApplyResult
    {
        return $data->status === PaymentStatus::Paid
            ? $this->applyPaid($data, $order)
            : $this->applyFailed($order);
    }

    private function applyPaid(PaymentEventData $data, Order $order): PaymentApplyResult
    {
        $status = $order->status;

        // Поздний paid поверх payment_failed побеждает: деньги реально пришли.
        if ($status === OrderStatus::PaymentFailed) {
            Log::warning('payment.out_of_order', [
                'event' => 'payment.out_of_order',
                'event_id' => $data->eventId,
                'order_id' => $order->id,
                'outcome' => 'payment_failed_overridden_by_paid',
            ]);
        } elseif ($status !== OrderStatus::Created) {
            // paid / delivering / delivered / out_of_stock / delivery_failed:
            // оплата уже учтена, задачу выдачи не дублируем.
            return PaymentApplyResult::IgnoredTerminal;
        }

        if (! $this->transitions->apply($order->id, [$status], OrderStatus::Paid, ['paid_at' => now()])) {
            // Гонку выиграл другой обработчик — это норма, не ошибка.
            return PaymentApplyResult::IgnoredTerminal;
        }

        $this->ledger->recordPayment($order, $data->eventId);

        // Выдача — только после коммита и только по факту перехода в paid.
        DeliverOrderJob::dispatch($order->id)->afterCommit();

        return PaymentApplyResult::Applied;
    }

    private function applyFailed(Order $order): PaymentApplyResult
    {
        if ($order->status === OrderStatus::Created) {
            return $this->transitions->apply($order->id, [OrderStatus::Created], OrderStatus::PaymentFailed)
                ? PaymentApplyResult::Applied
                : PaymentApplyResult::IgnoredTerminal;
        }

        if ($order->status === OrderStatus::PaymentFailed) {
            return PaymentApplyResult::IgnoredTerminal;
        }

        // Оплата уже подтверждена — откатывать статус запрещено (I5).
        return PaymentApplyResult::Stale;
    }

    /**
     * Сумма события сверяется с orders.amount напрямую, без пересчёта единиц.
     * Расхождение не отменяет применение — деньги пришли; оно поднимает флаг
     * для отчёта сверки.
     */
    private function amountMismatches(PaymentEventData $data, Order $order): bool
    {
        if ($data->amount === null || $data->currency === null) {
            return false;
        }

        return bccomp($data->amount, (string) $order->amount, 2) !== 0
            || $data->currency !== strtoupper(trim($order->currency));
    }

    private function finish(
        string $eventId,
        PaymentApplyResult $result,
        bool $applied,
        bool $mismatch = false,
    ): void {
        DB::table('payment_events')->where('event_id', $eventId)->update([
            'applied_at' => $applied ? now() : null,
            'apply_result' => $result->value,
            'amount_mismatch' => $mismatch,
        ]);
    }
}
