<?php

namespace App\Console\Commands;

use App\Domain\Reconcile\ReconcileReport;
use Illuminate\Console\Command;

/**
 * Тот же отчёт, что и GET /api/admin/reconcile, только в консоли (make reconcile).
 *
 * Код возврата ненулевой при `healthy: false` — чтобы цель годилась в CI
 * как проверка инварианта I6 и всего остального, что копится в отчёте.
 */
class ReconcileReportCommand extends Command
{
    protected $signature = 'reconcile:report {--json : Отдать отчёт как есть, машинно}';

    protected $description = 'Отчёт сверки: что не доехало, где деньги и сходится ли журнал';

    public function handle(ReconcileReport $report): int
    {
        $result = $report->build();

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $result['healthy'] ? self::SUCCESS : self::FAILURE;
        }

        $this->renderFindings($result);
        $this->renderLedger($result['ledger']);

        $this->newLine();

        if ($result['healthy']) {
            $this->info('  healthy: true — расхождений нет, журнал сходится');

            return self::SUCCESS;
        }

        $this->error('  healthy: false — см. находки выше');

        return self::FAILURE;
    }

    /** @param array<string, mixed> $result */
    private function renderFindings(array $result): void
    {
        $sections = array_diff_key($result, ['ledger' => null, 'healthy' => null]);

        $this->newLine();
        $this->table(
            ['Секция', 'Находок'],
            array_map(
                static fn (string $name, array $rows): array => [$name, count($rows)],
                array_keys($sections),
                $sections,
            ),
        );

        foreach ($sections as $name => $rows) {
            if ($rows === []) {
                continue;
            }

            $this->newLine();
            $this->line("  <comment>{$name}</comment>");
            $this->table(array_keys($rows[0]), array_map(
                static fn (array $row): array => array_map(
                    static fn ($value): string => match (true) {
                        $value === null => '—',
                        is_bool($value) => $value ? 'true' : 'false',
                        default => (string) $value,
                    },
                    $row,
                ),
                $rows,
            ));
        }
    }

    /** @param array<string, mixed> $ledger */
    private function renderLedger(array $ledger): void
    {
        $this->newLine();
        $this->line('  <comment>ledger</comment> (I6: sum(debit) = sum(credit))');
        $this->table(
            ['debit', 'credit', 'проводок', 'balanced'],
            [[$ledger['debit'], $ledger['credit'], $ledger['entries'], $ledger['balanced'] ? 'true' : 'false']],
        );
    }
}
