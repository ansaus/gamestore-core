<?php

use App\Domain\Ledger\LedgerEntry;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => seedShop());

/** @return array{debit: string, credit: string} */
function ledgerTotals(?string $orderId = null): array
{
    $query = DB::table('ledger_entries');

    if ($orderId !== null) {
        $query->where('order_id', $orderId);
    }

    $row = $query->selectRaw("
        coalesce(sum(amount) filter (where direction = 'debit'), 0) as debit,
        coalesce(sum(amount) filter (where direction = 'credit'), 0) as credit
    ")->first();

    return ['debit' => (string) $row->debit, 'credit' => (string) $row->credit];
}

it('сводит журнал после оплаты и выдачи', function () {
    useInProcessSupplier();

    $ids = collect(['KEY-CS2-PRIME', 'SUB-DISCORD-1M', 'GIFT-PSN-1000'])
        ->map(function (string $sku) {
            $id = $this->postJson('/api/orders', ['sku' => $sku])->json('id');
            $this->postJson('/api/webhooks/payment', webhookPayload($id))->assertOk();

            return $id;
        });

    $totals = ledgerTotals();
    expect(bccomp($totals['debit'], $totals['credit'], 2))->toBe(0)
        ->and(bccomp($totals['debit'], '0', 2))->toBe(1);

    foreach ($ids as $id) {
        $perOrder = ledgerTotals($id);
        expect(bccomp($perOrder['debit'], $perOrder['credit'], 2))->toBe(0);
    }
});

it('пишет проводку оплаты на сумму заказа', function () {
    useInProcessSupplier();

    $id = $this->postJson('/api/orders', ['sku' => 'KEY-CS2-PRIME'])->json('id');
    $this->postJson('/api/webhooks/payment', webhookPayload($id))->assertOk();

    $payment = LedgerEntry::where('order_id', $id)
        ->where('ref_type', 'payment_event')
        ->where('account', 'customer')
        ->firstOrFail();

    expect((string) $payment->amount)->toBe('1290.00')
        ->and($payment->direction)->toBe('debit');
});

it('не задваивает проводку при повторе события', function () {
    useInProcessSupplier();

    $id = $this->postJson('/api/orders', ['sku' => 'KEY-CS2-PRIME'])->json('id');
    $payload = webhookPayload($id);

    $this->postJson('/api/webhooks/payment', $payload)->assertOk();
    $this->postJson('/api/webhooks/payment', $payload)->assertOk();

    expect(LedgerEntry::where('ref_type', 'payment_event')->count())->toBe(2);

    $totals = ledgerTotals($id);
    expect(bccomp($totals['debit'], $totals['credit'], 2))->toBe(0);
});
