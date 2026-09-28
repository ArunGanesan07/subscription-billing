<?php

namespace App\Billing;

/**
 * How one segment of a cycle was (or is projected to be) billed.
 */
final readonly class SegmentResult
{
    public function __construct(
        public SegmentTerms $terms,
        public Period $coverage,
        public int $usage,
        public int $allowance,
        public int $overageUnits,
        public int $baseAmount,
        public int $overageAmount,
    ) {}
}
