<?php

namespace App\Billing;

use App\Enums\InvoiceLineType;

final readonly class InvoiceLineDraft
{
    public function __construct(
        public InvoiceLineType $type,
        public int $planId,
        public string $description,
        public Period $period,
        public int $quantity,
        public string $unitPrice,
        public int $amount,
        public array $meta = [],
    ) {}
}
