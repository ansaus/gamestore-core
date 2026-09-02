<?php

namespace App\Domain\Ledger;

use App\Domain\Order\Order;
use Illuminate\Support\Facades\DB;

class Ledger
{
    /**
     * Оплата: деньги пришли от клиента и стали выручкой.
     * ref — событие оплаты, поэтому повторное применение того же event_id
     * упрётся в UNIQUE и не задвоит проводку.
     */
    public function recordPayment(Order $order, string $eventId): void
    {
        $this->recordPair(
            orderId: $order->id,
            debit: LedgerAccount::Customer,
            credit: LedgerAccount::MerchantRevenue,
            amount: (string) $order->amount,
            refType: LedgerRefType::PaymentEvent,
            refId: $eventId,
        );
    }

    /**
     * Выдача: списываем условную себестоимость со склада в расходы.
     * Точность цифры не важна — важно, что проводка парная и журнал сходится (I6).
     */
    public function recordDelivery(Order $order, string $requestId): void
    {
        $rate = (float) config('gamestore.ledger.cogs_rate');
        $cogs = bcmul((string) $order->amount, (string) $rate, 2);

        $this->recordPair(
            orderId: $order->id,
            debit: LedgerAccount::Cogs,
            credit: LedgerAccount::Inventory,
            amount: $cogs,
            refType: LedgerRefType::Delivery,
            refId: $requestId,
        );
    }

    /**
     * Двойная запись одной операцией. insertOrIgnore поверх
     * unique (ref_type, ref_id, account, direction): повтор — не ошибка и не дубль.
     */
    private function recordPair(
        string $orderId,
        LedgerAccount $debit,
        LedgerAccount $credit,
        string $amount,
        LedgerRefType $refType,
        string $refId,
    ): void {
        // Проводка на ноль запрещена CHECK'ом и смысла не имеет.
        if (bccomp($amount, '0', 2) <= 0) {
            return;
        }

        $now = now();
        $common = [
            'order_id' => $orderId,
            'amount' => $amount,
            'ref_type' => $refType->value,
            'ref_id' => $refId,
            'created_at' => $now,
        ];

        DB::table('ledger_entries')->insertOrIgnore([
            $common + ['account' => $debit->value, 'direction' => 'debit'],
            $common + ['account' => $credit->value, 'direction' => 'credit'],
        ]);
    }
}
