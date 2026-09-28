<?php

namespace App\Billing;

use App\Enums\InvoiceLineType;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use LogicException;

/**
 * Pure billing math: no database, no clock. Given a cycle, the subscription's
 * segments and the customer's daily usage, it produces the invoice lines.
 *
 * Rules (see README "Billing rules"):
 *  - Each segment is billed independently at its own snapshot pricing.
 *  - Base price and included allowance are prorated by days covered:
 *      amount = round_half_up(full_amount × covered_days / cycle_days)
 *  - Overage = max(0, segment_usage − segment_allowance) × segment_rate,
 *    rounded half-up to the minor unit, per line.
 *  - Usage outside every segment (before the start, after cancellation) is not billed.
 */
final class InvoiceCalculator
{
    /**
     * @param  list<SegmentTerms>  $segments
     * @param  array<string, int>  $dailyUsage  units keyed by Y-m-d
     */
    public function calculate(Period $cycle, array $segments, array $dailyUsage): InvoiceDraft
    {
        $billable = [];

        foreach ($this->coverage($cycle, $segments) as [$terms, $coverage]) {
            $billable[] = [$terms, $coverage, $this->usageWithin($coverage, $dailyUsage)];
        }

        return $this->price($cycle, $billable);
    }

    /**
     * Same as calculate(), but for a cycle still in progress: usage in the
     * segment that is running on $asOf is extrapolated linearly to the end of
     * that segment's coverage. Used for "projected overage revenue".
     *
     * @param  list<SegmentTerms>  $segments
     * @param  array<string, int>  $dailyUsage
     */
    public function project(Period $cycle, array $segments, array $dailyUsage, CarbonInterface $asOf): InvoiceDraft
    {
        $asOf = CarbonImmutable::parse($asOf)->startOfDay();
        $billable = [];

        foreach ($this->coverage($cycle, $segments) as [$terms, $coverage]) {
            if ($coverage->start->greaterThan($asOf)) {
                $units = 0;
            } elseif ($coverage->end->lessThanOrEqualTo($asOf)) {
                $units = $this->usageWithin($coverage, $dailyUsage);
            } else {
                $elapsed = new Period($coverage->start, $asOf);
                $units = BigDecimal::of($this->usageWithin($elapsed, $dailyUsage))
                    ->multipliedBy($coverage->days())
                    ->dividedBy($elapsed->days(), 0, RoundingMode::HalfUp)
                    ->toInt();
            }

            $billable[] = [$terms, $coverage, $units];
        }

        return $this->price($cycle, $billable);
    }

    /**
     * @param  list<SegmentTerms>  $segments
     * @return list<array{0: SegmentTerms, 1: Period}>
     */
    private function coverage(Period $cycle, array $segments): array
    {
        usort($segments, fn (SegmentTerms $a, SegmentTerms $b) => $a->startsOn <=> $b->startsOn);

        $covered = [];
        $previous = null;

        foreach ($segments as $terms) {
            $coverage = $terms->coverageWithin($cycle);

            if ($coverage === null) {
                continue;
            }

            if ($previous !== null && $coverage->start->lessThanOrEqualTo($previous->end)) {
                throw new LogicException('Subscription segments overlap; refusing to double-bill.');
            }

            $covered[] = [$terms, $coverage];
            $previous = $coverage;
        }

        return $covered;
    }

    /**
     * @param  list<array{0: SegmentTerms, 1: Period, 2: int}>  $billable
     */
    private function price(Period $cycle, array $billable): InvoiceDraft
    {
        $cycleDays = $cycle->days();
        $segments = [];
        $lines = [];

        foreach ($billable as [$terms, $coverage, $usage]) {
            $days = $coverage->days();
            $isProrated = $days !== $cycleDays;

            $baseAmount = $this->prorate($terms->basePrice, $days, $cycleDays);
            $allowance = $this->prorate($terms->includedUnits, $days, $cycleDays);
            $overageUnits = max(0, $usage - $allowance);
            $overageAmount = BigDecimal::of($terms->overageRate)
                ->multipliedBy($overageUnits)
                ->toScale(0, RoundingMode::HalfUp)
                ->toInt();

            $segments[] = new SegmentResult($terms, $coverage, $usage, $allowance, $overageUnits, $baseAmount, $overageAmount);

            $lines[] = new InvoiceLineDraft(
                type: InvoiceLineType::Base,
                planId: $terms->planId,
                description: $isProrated
                    ? "{$terms->planName} plan (prorated {$days}/{$cycleDays} days)"
                    : "{$terms->planName} plan",
                period: $coverage,
                quantity: $days,
                unitPrice: (string) BigDecimal::of($terms->basePrice)->dividedBy($cycleDays, 6, RoundingMode::HalfUp),
                amount: $baseAmount,
                meta: [
                    'cycle_days' => $cycleDays,
                    'covered_days' => $days,
                    'full_base_price' => $terms->basePrice,
                    'included_units' => $terms->includedUnits,
                    'allowance' => $allowance,
                    'usage' => $usage,
                ],
            );

            if ($overageUnits > 0) {
                $lines[] = new InvoiceLineDraft(
                    type: InvoiceLineType::Overage,
                    planId: $terms->planId,
                    description: "{$terms->planName} overage: ".number_format($overageUnits).' units beyond '.number_format($allowance).' included',
                    period: $coverage,
                    quantity: $overageUnits,
                    unitPrice: (string) BigDecimal::of($terms->overageRate)->toScale(6),
                    amount: $overageAmount,
                    meta: ['usage' => $usage, 'allowance' => $allowance],
                );
            }
        }

        return new InvoiceDraft($cycle, $segments, $lines);
    }

    private function prorate(int $amount, int $days, int $cycleDays): int
    {
        if ($days === $cycleDays) {
            return $amount;
        }

        return BigDecimal::of($amount)
            ->multipliedBy($days)
            ->dividedBy($cycleDays, 0, RoundingMode::HalfUp)
            ->toInt();
    }

    /**
     * @param  array<string, int>  $dailyUsage
     */
    private function usageWithin(Period $period, array $dailyUsage): int
    {
        // Y-m-d strings compare correctly as strings; avoids parsing every day.
        $from = $period->start->toDateString();
        $to = $period->end->toDateString();
        $total = 0;

        foreach ($dailyUsage as $date => $units) {
            if ($date >= $from && $date <= $to) {
                $total += $units;
            }
        }

        return $total;
    }
}
