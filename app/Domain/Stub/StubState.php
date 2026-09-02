<?php

namespace App\Domain\Stub;

use Illuminate\Support\Facades\DB;

class StubState
{
    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $keys = StubKey::query()
            ->selectRaw('supplier, status, count(*) as total')
            ->groupBy('supplier', 'status')
            ->get();

        $suppliers = [];

        foreach (StubConfig::SUPPLIERS as $supplier) {
            $suppliers[$supplier] = [
                'free' => (int) ($keys->firstWhere(fn ($r) => $r->supplier === $supplier && $r->status === 'free')->total ?? 0),
                'issued' => (int) ($keys->firstWhere(fn ($r) => $r->supplier === $supplier && $r->status === 'issued')->total ?? 0),
                'calls' => StubConfig::calls($supplier),
                'config' => StubConfig::for($supplier),
            ];
        }

        return [
            'suppliers' => $suppliers,
            'issues' => StubIssue::query()
                ->orderBy('created_at')
                ->get(['request_id', 'supplier', 'order_id', 'sku', 'code'])
                ->toArray(),
        ];
    }

    /** Возвращает пул в исходное состояние: все ключи свободны, выдач нет. */
    public function reset(): void
    {
        DB::transaction(function (): void {
            StubIssue::query()->delete();
            StubKey::query()->update(['status' => 'free', 'request_id' => null]);
        });

        StubConfig::reset();
    }
}
