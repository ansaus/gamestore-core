<?php

namespace App\Console\Commands;

use App\Domain\Stub\StubState;
use Illuminate\Console\Command;

class StubResetCommand extends Command
{
    protected $signature = 'stub:reset';

    protected $description = 'Вернуть заглушку поставщика в исходное состояние: все ключи свободны, выдач нет';

    public function handle(StubState $state): int
    {
        $state->reset();

        foreach ($state->snapshot()['suppliers'] as $supplier => $row) {
            $this->line("Поставщик {$supplier}: свободно {$row['free']}, выдано {$row['issued']}");
        }

        return self::SUCCESS;
    }
}
