<?php

namespace App\Billing;

use App\Enums\InvoiceLineType;

/**
 * The computed (not yet persisted) bill for one cycle.
 */
final readonly class InvoiceDraft
{
    /**
     * @param  list<SegmentResult>  $segments
     * @param  list<InvoiceLineDraft>  $lines
     */
    public function __construct(
        public Period $cycle,
        public array $segments,
        public array $lines,
    ) {}

    public function total(): int
    {
        return array_sum(array_map(fn (InvoiceLineDraft $line) => $line->amount, $this->lines));
    }

    public function baseTotal(): int
    {
        return $this->sumOf(InvoiceLineType::Base);
    }

    public function overageTotal(): int
    {
        return $this->sumOf(InvoiceLineType::Overage);
    }

    public function usage(): int
    {
        return array_sum(array_map(fn (SegmentResult $s) => $s->usage, $this->segments));
    }

    public function allowance(): int
    {
        return array_sum(array_map(fn (SegmentResult $s) => $s->allowance, $this->segments));
    }

    private function sumOf(InvoiceLineType $type): int
    {
        return array_sum(array_map(
            fn (InvoiceLineDraft $line) => $line->type === $type ? $line->amount : 0,
            $this->lines,
        ));
    }
}
