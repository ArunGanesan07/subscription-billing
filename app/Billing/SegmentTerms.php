<?php

namespace App\Billing;

use App\Models\SubscriptionSegment;
use Carbon\CarbonImmutable;

/**
 * The pricing that applied to one stretch of a subscription (a snapshot of the
 * plan taken when the segment started).
 */
final readonly class SegmentTerms
{
    public function __construct(
        public int $planId,
        public string $planName,
        public CarbonImmutable $startsOn,
        public ?CarbonImmutable $endsOn,
        public int $basePrice,
        public int $includedUnits,
        public string $overageRate,
    ) {}

    public static function fromModel(SubscriptionSegment $segment): self
    {
        return new self(
            planId: $segment->plan_id,
            planName: $segment->plan->name,
            startsOn: CarbonImmutable::parse($segment->starts_on),
            endsOn: $segment->ends_on ? CarbonImmutable::parse($segment->ends_on) : null,
            basePrice: $segment->base_price,
            includedUnits: $segment->included_units,
            overageRate: (string) $segment->overage_rate,
        );
    }

    /** The part of $cycle this segment covers, or null if it doesn't touch it. */
    public function coverageWithin(Period $cycle): ?Period
    {
        $end = $this->endsOn ?? $cycle->end;

        if ($end->lessThan($this->startsOn)) {
            return null;
        }

        return (new Period($this->startsOn, $end))->overlap($cycle);
    }
}
