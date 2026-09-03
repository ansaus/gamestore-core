<?php

namespace App\Domain\Reconcile;

use Illuminate\Support\Facades\DB;

/**
 * Отчёт сверки (SPEC §8).
 *
 * Смысл — не «показать метрики», а ответить на один вопрос: есть ли сейчас
 * в системе деньги или коды, оказавшиеся не там, где должны. Поэтому каждая
 * секция — список находок, а не счётчик, и `healthy` равен «все секции пусты
 * и журнал сходится».
 *
 * Свежие расхождения находками не считаются: заказ, оплаченный секунду назад
 * и ещё не выданный, — это нормальная асинхронность, а не проблема. Порог —
 * `reconcile.grace_seconds`.
 */
class ReconcileReport
{
    /** @return array<string, mixed> */
    public function build(): array
    {
        $grace = (int) config('gamestore.reconcile.grace_seconds');
        $ledger = $this->ledger();

        $findings = [
            'paid_not_delivered' => $this->paidNotDelivered($grace),
            'delivered_not_paid' => $this->deliveredNotPaid(),
            'orphan_events' => $this->orphanEvents($grace),
            'stuck_in_delivering' => $this->stuckInDelivering($grace),
            'unknown_supplier_requests' => $this->unknownSupplierRequests(),
            'unclaimed_codes' => $this->unclaimedCodes(),
            'amount_mismatch_events' => $this->amountMismatchEvents(),
            'unbalanced_orders' => $this->unbalancedOrders(),
        ];

        $healthy = $ledger['balanced']
            && array_sum(array_map('count', $findings)) === 0;

        return $findings + ['ledger' => $ledger, 'healthy' => $healthy];
    }

    /**
     * Деньги пришли, товар не ушёл. Главная секция отчёта: здесь оседает всё,
     * что не доехало, независимо от причины.
     *
     * @return list<array<string, mixed>>
     */
    private function paidNotDelivered(int $grace): array
    {
        return $this->rows("
            select o.id as order_id, o.status, o.attempts, o.paid_at, o.next_attempt_at,
                   extract(epoch from now() - o.paid_at)::int as age_sec
            from orders o
            left join deliveries d on d.order_id = o.id
            where o.status in ('paid', 'delivering', 'out_of_stock', 'delivery_failed')
              and d.order_id is null
              and o.paid_at <= now() - make_interval(secs => ?)
            order by o.paid_at
        ", [$grace]);
    }

    /**
     * Товар ушёл, денег нет. Пустой всегда: выдача возможна только из
     * delivering, а туда попадают только с подтверждённой оплаты. Секция
     * существует ровно затем, чтобы это утверждение проверялось, а не
     * принималось на веру.
     *
     * @return list<array<string, mixed>>
     */
    private function deliveredNotPaid(): array
    {
        return $this->rows("
            select d.order_id, o.status, d.code, d.supplier, d.created_at as delivered_at
            from deliveries d
            join orders o on o.id = d.order_id
            where o.paid_at is null
               or o.status not in ('delivered', 'delivering')
            order by d.created_at
        ");
    }

    /**
     * События, не применённые ни к одному заказу. После grace это либо заказ,
     * которого нет и не будет, либо сломанный подхват.
     *
     * @return list<array<string, mixed>>
     */
    private function orphanEvents(int $grace): array
    {
        return $this->rows('
            select e.event_id, e.order_id, e.status, e.received_at,
                   extract(epoch from now() - e.received_at)::int as age_sec,
                   (o.id is not null) as order_exists
            from payment_events e
            left join orders o on o.id = e.order_id
            where e.applied_at is null
              and e.received_at <= now() - make_interval(secs => ?)
            order by e.received_at
        ', [$grace]);
    }

    /**
     * Заказы, застрявшие в выдаче. Ожидаемое состояние для невыясненного
     * исхода, но только до тех пор, пока за ними приходит watchdog.
     *
     * @return list<array<string, mixed>>
     */
    private function stuckInDelivering(int $grace): array
    {
        return $this->rows("
            select id as order_id, attempts, next_attempt_at, updated_at,
                   extract(epoch from now() - updated_at)::int as age_sec
            from orders
            where status = 'delivering'
              and updated_at <= now() - make_interval(secs => ?)
            order by updated_at
        ", [$grace]);
    }

    /**
     * Заявки, по которым мы так и не знаем, выдал поставщик код или нет.
     * Без этой секции они невидимы: заказ может быть уже выдан другим
     * поставщиком, а зависшая заявка останется висеть молча.
     *
     * @return list<array<string, mixed>>
     */
    private function unknownSupplierRequests(): array
    {
        return $this->rows("
            select r.request_id, r.order_id, r.supplier, r.sku, r.attempts,
                   r.error_reason, o.status as order_status,
                   extract(epoch from now() - r.updated_at)::int as age_sec
            from supplier_requests r
            join orders o on o.id = r.order_id
            where r.state = 'unknown'
            order by r.updated_at
        ");
    }

    /**
     * Коды, за которые заплачено, но которые заказу уже не нужны. Деньги
     * поставщику ушли — это к возврату, а не к забвению.
     *
     * @return list<array<string, mixed>>
     */
    private function unclaimedCodes(): array
    {
        return $this->rows('
            select id, request_id, order_id, supplier, code, reason, created_at
            from unclaimed_codes
            order by created_at
        ');
    }

    /**
     * Платёжка прислала не ту сумму. Событие применено — деньги пришли, —
     * но разбираться с разницей всё равно человеку.
     *
     * @return list<array<string, mixed>>
     */
    private function amountMismatchEvents(): array
    {
        return $this->rows('
            select e.event_id, e.order_id, e.amount as event_amount, e.currency as event_currency,
                   o.amount as order_amount, o.currency as order_currency, e.received_at
            from payment_events e
            join orders o on o.id = e.order_id
            where e.amount_mismatch
            order by e.received_at
        ');
    }

    /**
     * I6 в разрезе заказа: журнал обязан сходиться не только в сумме по всей
     * базе, но и по каждому заказу отдельно. Глобальный ноль легко получить
     * из двух ошибок, компенсирующих друг друга на разных заказах.
     *
     * @return list<array<string, mixed>>
     */
    private function unbalancedOrders(): array
    {
        return $this->rows("
            select order_id,
                   coalesce(sum(amount) filter (where direction = 'debit'), 0) as debit,
                   coalesce(sum(amount) filter (where direction = 'credit'), 0) as credit
            from ledger_entries
            group by order_id
            having coalesce(sum(amount) filter (where direction = 'debit'), 0)
                <> coalesce(sum(amount) filter (where direction = 'credit'), 0)
            order by order_id
        ");
    }

    /**
     * I6 глобально: sum(debit) = sum(credit).
     *
     * Сравниваем через bccomp по строкам, а не по float: numeric(12,2),
     * загнанный в double, — это ровно тот способ потерять копейку, от
     * которого весь проект и защищается.
     *
     * @return array{debit: string, credit: string, entries: int, balanced: bool}
     */
    private function ledger(): array
    {
        $sums = DB::selectOne("
            select coalesce(sum(amount) filter (where direction = 'debit'), 0)::text as debit,
                   coalesce(sum(amount) filter (where direction = 'credit'), 0)::text as credit,
                   count(*)::int as entries
            from ledger_entries
        ");

        return [
            'debit' => $sums->debit,
            'credit' => $sums->credit,
            'entries' => $sums->entries,
            'balanced' => bccomp($sums->debit, $sums->credit, 2) === 0,
        ];
    }

    /**
     * @param  list<mixed>  $bindings
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $bindings = []): array
    {
        return array_map(
            static fn (object $row): array => (array) $row,
            DB::select($sql, $bindings),
        );
    }
}
