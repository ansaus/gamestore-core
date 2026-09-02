<?php

namespace App\Domain\Stub;

use RuntimeException;

/**
 * Две попытки с одним request_id доехали до выдачи одновременно.
 * Проигравший откатывает занятый ключ и отдаёт код победителя.
 */
class StubRaceException extends RuntimeException {}
