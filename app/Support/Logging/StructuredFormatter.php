<?php

namespace App\Support\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;

/**
 * Одна строка JSON на событие, поля из SPEC §8 — на верхнем уровне.
 *
 * Стандартный JsonFormatter прячет их в context, и запрос «вся история по
 * одному заказу» превращается в `.context.order_id`. Здесь же:
 *
 *   docker compose logs worker | jq 'select(.order_id == "ord_00042")'
 *
 * Ключи с null не печатаются: в строке про платёж нет supplier, и место
 * под него занимать незачем.
 */
class StructuredFormatter extends JsonFormatter
{
    /** Поля, обязательные по SPEC §8, в фиксированном порядке. */
    private const FIELDS = [
        'order_id', 'event_id', 'request_id',
        'supplier', 'attempt', 'outcome', 'duration_ms',
    ];

    /** @return array<string, mixed> */
    protected function normalizeRecord(LogRecord $record): array
    {
        // Нормализацию значений (в том числе исключений) отдаём родителю,
        // а раскладываем по строке уже сами.
        $normalized = parent::normalizeRecord($record);
        $context = $normalized['context'] ?? [];

        $line = [
            'ts' => $record->datetime->format('Y-m-d\TH:i:s.uP'),
            'level' => strtolower($record->level->getName()),
            'event' => $context['event'] ?? $normalized['message'],
        ];
        unset($context['event']);

        foreach (self::FIELDS as $field) {
            if (($context[$field] ?? null) !== null) {
                $line[$field] = $context[$field];
            }

            unset($context[$field]);
        }

        $line['channel'] = $normalized['channel'];

        // Всё, что не входит в обязательный набор, — отдельным объектом.
        if ($context !== []) {
            $line['context'] = $context;
        }

        return $line;
    }
}
