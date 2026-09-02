<?php

namespace App\Support\Supplier;

final readonly class SupplierOutcome
{
    private function __construct(
        public SupplierOutcomeKind $kind,
        public ?string $code = null,
        public ?string $reason = null,
    ) {}

    public static function succeeded(string $code): self
    {
        return new self(SupplierOutcomeKind::Succeeded, code: $code);
    }

    public static function rejected(string $reason): self
    {
        return new self(SupplierOutcomeKind::Rejected, reason: $reason);
    }

    public static function unknown(string $reason): self
    {
        return new self(SupplierOutcomeKind::Unknown, reason: $reason);
    }
}
