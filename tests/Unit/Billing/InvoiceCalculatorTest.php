<?php

namespace Tests\Unit\Billing;

use App\Billing\InvoiceCalculator;
use App\Billing\InvoiceDraft;
use App\Billing\Period;
use App\Billing\SegmentTerms;
use App\Enums\InvoiceLineType;
use Carbon\CarbonImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pure maths: no framework, no database.
 *
 * Fixture plans (amounts in paise):
 *   Growth: ₹3,000 / month, 3,000 units included, ₹0.50 (50 paise) per extra unit
 *   Scale:  ₹9,000 / month, 10,000 units included, ₹0.20 (20 paise) per extra unit
 */
class InvoiceCalculatorTest extends TestCase
{
    private InvoiceCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new InvoiceCalculator;
    }

    // ---------------------------------------------------------------- basics

    #[Test]
    public function full_cycle_within_allowance_bills_only_the_base_price(): void
    {
        $draft = $this->bill('2026-09', [$this->growth('2026-09-01')], $this->spread('2026-09-01', '2026-09-30', 2500));

        $this->assertSame(300000, $draft->total());
        $this->assertCount(1, $draft->lines);
        $this->assertSame('Growth plan', $draft->lines[0]->description);
    }

    #[Test]
    public function usage_exactly_at_the_allowance_has_no_overage(): void
    {
        $draft = $this->bill('2026-09', [$this->growth('2026-09-01')], ['2026-09-10' => 3000]);

        $this->assertSame(0, $draft->overageTotal());
        $this->assertSame(300000, $draft->total());
    }

    #[Test]
    public function a_single_unit_over_the_allowance_is_charged(): void
    {
        $draft = $this->bill('2026-09', [$this->growth('2026-09-01')], ['2026-09-10' => 3001]);

        $this->assertSame(50, $draft->overageTotal());
        $this->assertSame(300050, $draft->total());
    }

    #[Test]
    public function overage_is_units_beyond_allowance_times_rate(): void
    {
        $draft = $this->bill('2026-09', [$this->growth('2026-09-01')], $this->spread('2026-09-01', '2026-09-30', 4000));

        $overage = $this->line($draft, InvoiceLineType::Overage);
        $this->assertSame(1000, $overage->quantity);
        $this->assertSame(50000, $overage->amount);
        $this->assertSame(350000, $draft->total());
    }

    #[Test]
    public function zero_usage_still_bills_the_base_price(): void
    {
        $draft = $this->bill('2026-09', [$this->growth('2026-09-01')], []);

        $this->assertSame(300000, $draft->total());
    }

    #[Test]
    public function a_subscription_that_started_in_an_earlier_cycle_is_billed_in_full(): void
    {
        $draft = $this->bill('2026-09', [$this->growth('2026-06-17')], []);

        $this->assertSame(300000, $draft->total());
        $this->assertSame(30, $draft->lines[0]->quantity);
    }

    // ------------------------------------------------------------- proration

    #[Test]
    public function mid_cycle_start_prorates_base_price_and_allowance(): void
    {
        // 16–30 Sep = 15 of 30 days → half the base price and half the allowance.
        $draft = $this->bill('2026-09', [$this->growth('2026-09-16')], [
            '2026-09-10' => 999,   // before the subscription started: not billable
            '2026-09-20' => 2000,
        ]);

        $base = $this->line($draft, InvoiceLineType::Base);
        $this->assertSame(150000, $base->amount);
        $this->assertSame(15, $base->quantity);
        $this->assertSame('Growth plan (prorated 15/30 days)', $base->description);
        $this->assertSame(1500, $draft->segments[0]->allowance);

        // 2000 used − 1500 prorated allowance = 500 × 50 paise.
        $this->assertSame(25000, $draft->overageTotal());
        $this->assertSame(175000, $draft->total());
    }

    #[Test]
    public function starting_on_the_last_day_bills_one_day(): void
    {
        $draft = $this->bill('2026-09', [$this->growth('2026-09-30')], ['2026-09-30' => 150]);

        $this->assertSame(10000, $draft->baseTotal());         // 300000 × 1/30
        $this->assertSame(100, $draft->allowance());           // 3000 × 1/30
        $this->assertSame(2500, $draft->overageTotal());       // 50 × 50
    }

    #[Test]
    public function proration_rounds_half_up_to_the_minor_unit_in_a_31_day_month(): void
    {
        // 11–31 Oct = 21/31 days. 300000 × 21/31 = 203225.806… → 203226.
        // 3000 × 21/31 = 2032.258… → 2032 units.
        $draft = $this->bill('2026-10', [$this->growth('2026-10-11')], []);

        $this->assertSame(203226, $draft->baseTotal());
        $this->assertSame(2032, $draft->allowance());
    }

    #[Test]
    public function proration_uses_29_days_in_a_leap_year_february(): void
    {
        // 15–29 Feb 2028 = 15/29 days. 300000 × 15/29 = 155172.41… → 155172.
        $draft = $this->bill('2028-02', [$this->growth('2028-02-15')], []);

        $this->assertSame(29, $draft->cycle->days());
        $this->assertSame(155172, $draft->baseTotal());
    }

    #[Test]
    public function fractional_per_unit_rates_round_half_up_per_line(): void
    {
        // 0.5 paise per unit: 3 extra units = 1.5 paise → 2; 1 extra unit = 0.5 → 1.
        $cheap = fn () => new SegmentTerms(3, 'Micro', CarbonImmutable::parse('2026-09-01'), null, 0, 100, '0.5');

        $this->assertSame(2, $this->bill('2026-09', [$cheap()], ['2026-09-05' => 103])->overageTotal());
        $this->assertSame(1, $this->bill('2026-09', [$cheap()], ['2026-09-05' => 101])->overageTotal());
    }

    #[Test]
    public function cancellation_mid_cycle_prorates_and_ignores_later_usage(): void
    {
        $segment = $this->growth('2026-09-01', endsOn: '2026-09-20');   // 20/30 days

        $draft = $this->bill('2026-09', [$segment], [
            '2026-09-05' => 2500,   // allowance for 20 days = 2000 → 500 over
            '2026-09-25' => 9999,   // after cancellation: not billed
        ]);

        $this->assertSame(200000, $draft->baseTotal());
        $this->assertSame(2500, $draft->usage());
        $this->assertSame(25000, $draft->overageTotal());
    }

    #[Test]
    public function a_segment_outside_the_cycle_contributes_nothing(): void
    {
        $draft = $this->bill('2026-09', [$this->growth('2026-07-01', endsOn: '2026-08-31')], ['2026-09-02' => 500]);

        $this->assertSame([], $draft->lines);
        $this->assertSame(0, $draft->total());
    }

    // ------------------------------------------------- mid-cycle plan changes

    #[Test]
    public function upgrade_mid_cycle_bills_each_segment_at_its_own_rate(): void
    {
        // Growth 1–15 Sep, Scale from 16 Sep.
        $segments = [
            $this->growth('2026-09-01', endsOn: '2026-09-15'),
            $this->scale('2026-09-16'),
        ];
        $usage = $this->spread('2026-09-01', '2026-09-15', 3000)   // Growth allowance 1500 → 1500 over @ 50
            + $this->spread('2026-09-16', '2026-09-30', 12000);      // Scale allowance 5000 → 7000 over @ 20

        $draft = $this->bill('2026-09', $segments, $usage);

        [$growth, $scale] = $draft->segments;
        $this->assertSame([3000, 1500, 1500, 150000, 75000], [$growth->usage, $growth->allowance, $growth->overageUnits, $growth->baseAmount, $growth->overageAmount]);
        $this->assertSame([12000, 5000, 7000, 450000, 140000], [$scale->usage, $scale->allowance, $scale->overageUnits, $scale->baseAmount, $scale->overageAmount]);
        $this->assertSame(815000, $draft->total());
        $this->assertCount(4, $draft->lines);
    }

    #[Test]
    public function pre_change_usage_is_not_repriced_at_the_new_plans_rate(): void
    {
        // All usage happens before the upgrade. Pooling it with the new plan's
        // allowance would wipe out the overage; billing per segment keeps it.
        $segments = [
            $this->growth('2026-09-01', endsOn: '2026-09-15'),
            $this->scale('2026-09-16'),
        ];

        $draft = $this->bill('2026-09', $segments, ['2026-09-10' => 4000]);

        $this->assertSame(2500 * 50, $draft->overageTotal());   // 4000 − 1500 at Growth's rate
    }

    #[Test]
    public function downgrade_mid_cycle_prorates_both_segments(): void
    {
        // Scale 1–10 Sep (10/30), Growth 11–30 Sep (20/30).
        $segments = [
            $this->scale('2026-09-01', endsOn: '2026-09-10'),
            $this->growth('2026-09-11'),
        ];

        $draft = $this->bill('2026-09', $segments, [
            '2026-09-05' => 3000,   // Scale allowance 3333 → no overage
            '2026-09-15' => 2500,   // Growth allowance 2000 → 500 over @ 50
        ]);

        $this->assertSame(300000 + 200000, $draft->baseTotal());
        $this->assertSame([3333, 2000], array_map(fn ($s) => $s->allowance, $draft->segments));
        $this->assertSame(25000, $draft->overageTotal());
        $this->assertSame(525000, $draft->total());
    }

    #[Test]
    public function several_changes_in_one_cycle_cover_every_day_exactly_once(): void
    {
        $segments = [
            $this->growth('2026-09-01', endsOn: '2026-09-09'),
            $this->scale('2026-09-10', endsOn: '2026-09-19'),
            $this->growth('2026-09-20'),
        ];

        $draft = $this->bill('2026-09', $segments, []);

        $this->assertSame(30, array_sum(array_map(fn ($s) => $s->coverage->days(), $draft->segments)));
        // 300000×9/30 + 900000×10/30 + 300000×11/30
        $this->assertSame(90000 + 300000 + 110000, $draft->total());
    }

    #[Test]
    public function overlapping_segments_are_rejected_rather_than_double_billed(): void
    {
        $this->expectException(LogicException::class);

        $this->bill('2026-09', [$this->growth('2026-09-01'), $this->scale('2026-09-15')], []);
    }

    // ------------------------------------------------------------ projection

    #[Test]
    public function projection_extrapolates_the_running_segment_to_cycle_end(): void
    {
        // 150/day for the first 10 days → 4500 projected over 30 days → 1500 over.
        $draft = $this->calculator->project(
            $this->cycle('2026-09'),
            [$this->growth('2026-09-01')],
            $this->spread('2026-09-01', '2026-09-10', 1500),
            CarbonImmutable::parse('2026-09-10'),
        );

        $this->assertSame(4500, $draft->usage());
        $this->assertSame(75000, $draft->overageTotal());
    }

    #[Test]
    public function projection_uses_actual_usage_for_segments_that_already_ended(): void
    {
        $draft = $this->calculator->project(
            $this->cycle('2026-09'),
            [$this->growth('2026-09-01', endsOn: '2026-09-15'), $this->scale('2026-09-16')],
            ['2026-09-05' => 1000] + $this->spread('2026-09-16', '2026-09-20', 2000),
            CarbonImmutable::parse('2026-09-20'),
        );

        [$growth, $scale] = $draft->segments;
        $this->assertSame(1000, $growth->usage);            // closed: actual
        $this->assertSame(6000, $scale->usage);             // 2000 in 5 of 15 days → ×3
    }

    #[Test]
    public function projection_at_cycle_end_matches_the_real_invoice(): void
    {
        $segments = [$this->growth('2026-09-01', endsOn: '2026-09-12'), $this->scale('2026-09-13')];
        $usage = $this->spread('2026-09-01', '2026-09-30', 20000);

        $this->assertSame(
            $this->bill('2026-09', $segments, $usage)->total(),
            $this->calculator->project($this->cycle('2026-09'), $segments, $usage, CarbonImmutable::parse('2026-09-30'))->total(),
        );
    }

    // --------------------------------------------------------------- helpers

    private function bill(string $month, array $segments, array $usage): InvoiceDraft
    {
        return $this->calculator->calculate($this->cycle($month), $segments, $usage);
    }

    private function cycle(string $month): Period
    {
        $start = CarbonImmutable::parse("{$month}-01");

        return new Period($start, $start->endOfMonth());
    }

    private function growth(string $startsOn, ?string $endsOn = null): SegmentTerms
    {
        return new SegmentTerms(1, 'Growth', CarbonImmutable::parse($startsOn), $endsOn ? CarbonImmutable::parse($endsOn) : null, 300000, 3000, '50');
    }

    private function scale(string $startsOn, ?string $endsOn = null): SegmentTerms
    {
        return new SegmentTerms(2, 'Scale', CarbonImmutable::parse($startsOn), $endsOn ? CarbonImmutable::parse($endsOn) : null, 900000, 10000, '20');
    }

    /** Spreads $total units evenly across the days (remainder on the first day). */
    private function spread(string $from, string $to, int $total): array
    {
        $dates = (new Period($from, $to))->dates();
        $each = intdiv($total, count($dates));
        $usage = array_fill_keys($dates, $each);
        $usage[$dates[0]] += $total - $each * count($dates);

        return $usage;
    }

    private function line(InvoiceDraft $draft, InvoiceLineType $type)
    {
        foreach ($draft->lines as $line) {
            if ($line->type === $type) {
                return $line;
            }
        }

        $this->fail("No {$type->value} line on the invoice.");
    }
}
