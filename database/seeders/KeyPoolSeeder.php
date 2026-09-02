<?php

namespace Database\Seeders;

use App\Domain\Stub\StubIssue;
use App\Domain\Stub\StubKey;
use Illuminate\Database\Seeder;
use RuntimeException;

class KeyPoolSeeder extends Seeder
{
    /**
     * Пул из docs/keys.json делится между поставщиками: первые 30 → A,
     * последние 20 → B. Деление фиксированное, а не случайное — иначе
     * сценарии out_of_stock и фолбэка невоспроизводимы.
     */
    public function run(): void
    {
        $path = base_path('docs/keys.json');
        $keys = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)['keys'] ?? [];

        $split = config('gamestore.stub.key_split');
        $expected = array_sum($split);

        if (count($keys) < $expected) {
            throw new RuntimeException("В {$path} ключей ".count($keys).", нужно {$expected}.");
        }

        StubIssue::query()->delete();
        StubKey::query()->delete();

        $offset = 0;
        $rows = [];

        foreach ($split as $supplier => $count) {
            foreach (array_slice($keys, $offset, $count) as $code) {
                $rows[] = ['supplier' => $supplier, 'code' => $code, 'status' => 'free', 'request_id' => null];
            }

            $offset += $count;
        }

        StubKey::insert($rows);

        $this->command?->info('Пул заглушки: '.implode(', ', array_map(
            fn ($s, $c) => "{$s}={$c}",
            array_keys($split),
            $split,
        )));
    }
}
