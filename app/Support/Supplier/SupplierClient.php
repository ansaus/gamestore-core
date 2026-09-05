<?php

namespace App\Support\Supplier;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP-клиент поставщика.
 *
 * Две вещи, ради которых класс существует.
 *
 * 1. Классификация исхода. Определённый отказ и неопределённость —
 *    разные вещи: из неопределённости нельзя уходить к другому поставщику,
 *    иначе спишем два ключа за один заказ.
 *
 * 2. Ретраи с НЕИЗМЕННЫМ request_id. Это единственный способ выполнить
 *    требование «повтор после таймаута не создаёт вторую выдачу»: повтор
 *    работает как probe — он либо вернёт код, выданный на первой попытке,
 *    либо выдаст новый, но никогда не выдаст второй.
 *
 * Ретраим только неопределённость. Успех ретраить незачем, а определённый
 * отказ поставщик уже подтвердил — повтор ничего не изменит.
 */
class SupplierClient
{
    public function issue(string $supplier, string $requestId, string $orderId, string $sku): SupplierOutcome
    {
        $maxAttempts = max(1, (int) config('gamestore.supplier.max_attempts'));
        $outcome = SupplierOutcome::unknown('not_attempted');

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $outcome = $this->attempt($supplier, $requestId, $orderId, $sku, $attempt);

            if ($outcome->kind !== SupplierOutcomeKind::Unknown) {
                return $outcome->afterAttempts($attempt);
            }

            if ($attempt < $maxAttempts) {
                $this->backoff($attempt);
            }
        }

        return $outcome->afterAttempts($maxAttempts);
    }

    /** Одна попытка. request_id тот же самый, что и на всех остальных. */
    private function attempt(
        string $supplier,
        string $requestId,
        string $orderId,
        string $sku,
        int $attempt,
    ): SupplierOutcome {
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
            $this->log($supplier, $requestId, $orderId, $outcome, null, $startedAt, $attempt);

            return $outcome;
        }

        $outcome = $this->classify($response->status(), $response->json());
        $this->log($supplier, $requestId, $orderId, $outcome, $response->status(), $startedAt, $attempt);

        return $outcome;
    }

    /**
     * Экспоненциальный бэкофф с джиттером ±30%.
     *
     * Джиттер не украшение: без него все заказы, упавшие в одну секунду,
     * пойдут на ретрай одной волной и добьют поставщика, который только
     * начал подниматься.
     */
    private function backoff(int $attempt): void
    {
        $base = (int) config('gamestore.supplier.backoff_ms') * (2 ** ($attempt - 1));

        if ($base <= 0) {
            return;
        }

        $jitter = random_int(-30, 30) / 100;

        usleep((int) round($base * (1 + $jitter) * 1000));
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
        int $attempt,
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
            'attempt' => $attempt,
            'http_status' => $status,
            'outcome' => strtolower($outcome->kind->name),
            'reason' => $outcome->reason,
            'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
        ]);
    }
}
