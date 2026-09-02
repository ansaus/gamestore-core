<?php

namespace App\Domain\Stub;

final readonly class StubIssueResult
{
    private function __construct(
        public int $status,
        public ?string $code = null,
        public ?string $reason = null,
        public bool $replayed = false,
    ) {}

    public static function issued(string $code, bool $replayed = false): self
    {
        return new self(200, code: $code, replayed: $replayed);
    }

    public static function outOfStock(): self
    {
        return new self(409, reason: 'out_of_stock');
    }

    public static function internal(): self
    {
        return new self(500, reason: 'internal');
    }
}
