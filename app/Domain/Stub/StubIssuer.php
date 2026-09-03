<?php

namespace App\Domain\Stub;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Выдача ключа заглушкой поставщика.
 *
 * Главное требование контракта (I4): повтор с тем же request_id обязан
 * вернуть ТОТ ЖЕ код. Отсюда порядок действий — сначала выдача фиксируется
 * в БД, и только потом заглушка «зависает» или падает. Если сделать наоборот,
 * сценарий «таймаут, но код уже выдан» просто не воспроизведётся.
 */
class StubIssuer
{
    public function issue(string $supplier, string $requestId, string $orderId, string $sku): StubIssueResult
    {
        StubConfig::countCall($supplier);
        $config = StubConfig::for($supplier);

        // Повтор известного request_id обслуживается без инъекции отказов:
        // поставщик, однажды выдавший код, обязан его отдавать.
        $existing = StubIssue::find($requestId);

        if ($existing !== null) {
            return StubIssueResult::issued($existing->code, replayed: true);
        }

        $this->sleepMs((int) $config['latency_ms']);

        [$rejection, $hangAfterIssue] = $this->plannedBehaviour($config);

        if ($rejection !== null) {
            return $rejection;
        }

        try {
            $code = DB::transaction(fn () => $this->takeKey($supplier, $requestId, $orderId, $sku));
        } catch (StubRaceException) {
            $existing = StubIssue::find($requestId);

            return $existing !== null
                ? StubIssueResult::issued($existing->code, replayed: true)
                : StubIssueResult::internal();
        }

        if ($code === null) {
            return StubIssueResult::outOfStock();
        }

        // Выдача уже в БД. Теперь можно «потерять» ответ — код от этого
        // не пропадёт, и повтор вернёт именно его.
        if ($hangAfterIssue) {
            $this->sleepMs((int) $config['timeout_sleep_ms']);
        }

        return StubIssueResult::issued($code);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{0: ?StubIssueResult, 1: bool} отказ до выдачи и признак «зависнуть после выдачи»
     */
    private function plannedBehaviour(array $config): array
    {
        return match ($config['force']) {
            'out_of_stock' => [StubIssueResult::outOfStock(), false],
            'http_500' => [StubIssueResult::internal(), false],
            'timeout_after_issue' => [null, true],
            default => [
                $this->roll((float) $config['fail_rate']) ? StubIssueResult::internal() : null,
                $this->roll((float) $config['timeout_rate']),
            ],
        };
    }

    /** Занимает свободный ключ. SKIP LOCKED: параллельные покупки не встают в очередь. */
    private function takeKey(string $supplier, string $requestId, string $orderId, string $sku): ?string
    {
        $table = config('gamestore.stub.schema').'.stub_keys';

        // sku is null — универсальный ключ (демо-пул из docs/keys.json).
        // На нагрузочном датасете ключи привязаны к товару, и тогда заказу
        // достаётся ключ именно его SKU.
        $row = DB::selectOne("
            update {$table} set request_id = ?, status = 'issued'
            where id = (
                select id from {$table}
                where supplier = ? and status = 'free' and (sku is null or sku = ?)
                order by id
                for update skip locked
                limit 1
            )
            returning code
        ", [$requestId, $supplier, $sku]);

        if ($row === null) {
            return null;
        }

        try {
            StubIssue::create([
                'request_id' => $requestId,
                'supplier' => $supplier,
                'order_id' => $orderId,
                'sku' => $sku,
                'code' => $row->code,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Откатываем занятый ключ вместе с транзакцией.
            throw new StubRaceException;
        }

        return $row->code;
    }

    private function roll(float $probability): bool
    {
        return $probability > 0 && mt_rand() / mt_getrandmax() < $probability;
    }

    private function sleepMs(int $ms): void
    {
        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }
}
