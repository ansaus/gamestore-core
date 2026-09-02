<?php

namespace App\Support\Supplier;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP-клиент поставщика.
 *
 * Вся ценность класса — в классификации исхода (SPEC §7.2). Отличать
 * определённый отказ от неопределённости обязательно: из неопределённости
 * нельзя уходить к другому поставщику, иначе спишем два ключа.
 *
 * Ретраи и фолбэк — этап 3. Здесь одна попытка.
 */
class SupplierClient
{
    public function issue(string $supplier, string $requestId, string $orderId, string $sku): SupplierOutcome
    {
        $url = rtrim((string) config('gamestore.supplier.base_url'), '/')
            .'/supplier/'.strtolower($supplier).'/issue';

        $startedAt = hrtime(true);

        try {
            $response = Http::connectTimeout((float) config('gamestore.supplier.connect_timeout'))
                ->timeout((float) config('gamestore.supplier.timeout'))
                ->acceptJson()
                ->post($url, [
                    'request_id' => $requestId,
                    'order_id' => $orderId,
                    'sku' => $sku,
                ]);
        } catch (ConnectionException $e) {
            $outcome = SupplierOutcome::unknown('timeout');
            $this->log($supplier, $requestId, $orderId, $outcome, null, $startedAt);

            return $outcome;
        }

        $outcome = $this->classify($response->status(), $response->json());
        $this->log($supplier, $requestId, $orderId, $outcome, $response->status(), $startedAt);

        return $outcome;
    }

    /** @param array<string, mixed>|null $body */
    private function classify(int $status, ?array $body): SupplierOutcome
    {
        $code = is_string($body['code'] ?? null) ? $body['code'] : null;
        $reason = is_string($body['reason'] ?? null) ? $body['reason'] : "http_{$status}";

        if ($status === 200 && ($body['status'] ?? null) === 'ok' && $code !== null) {
            return SupplierOutcome::succeeded($code);
        }

        // 5xx — поставщик мог успеть выдать код до того, как упал.
        if ($status >= 500) {
            return SupplierOutcome::unknown($reason);
        }

        // 200 без кода трактуем как неопределённость: контракт нарушен,
        // а значит мы не знаем, что там произошло с ключом.
        if ($status < 400) {
            return SupplierOutcome::unknown('malformed_response');
        }

        return SupplierOutcome::rejected($reason);
    }

    private function log(
        string $supplier,
        string $requestId,
        string $orderId,
        SupplierOutcome $outcome,
        ?int $status,
        int $startedAt,
    ): void {
        $event = match ($outcome->kind) {
            SupplierOutcomeKind::Unknown => $outcome->reason === 'timeout'
                ? 'supplier.timeout'
                : 'supplier.unknown',
            default => 'supplier.call',
        };

        Log::info($event, [
            'event' => $event,
            'order_id' => $orderId,
            'request_id' => $requestId,
            'supplier' => $supplier,
            'http_status' => $status,
            'outcome' => strtolower($outcome->kind->name),
            'reason' => $outcome->reason,
            'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
        ]);
    }
}
