<?php

namespace Tests\Unit\Billing;

use App\Billing\Period;
use App\Enums\BillingInterval;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class BillingCycleTest extends TestCase
{
    #[Test]
    public function monthly_cycles_are_calendar_months(): void
    {
        $cycle = BillingInterval::Monthly->cycleContaining(CarbonImmutable::parse('2026-09-17 15:30'));

        $this->assertSame(['start' => '2026-09-01', 'end' => '2026-09-30'], $cycle->toArray());
        $this->assertSame(30, $cycle->days());
    }

    #[Test]
    public function february_has_29_days_in_a_leap_year(): void
    {
        $this->assertSame(29, BillingInterval::Monthly->cycleContaining(CarbonImmutable::parse('2028-02-10'))->days());
        $this->assertSame(28, BillingInterval::Monthly->cycleContaining(CarbonImmutable::parse('2027-02-10'))->days());
    }

    #[Test]
    public function quarterly_and_yearly_cycles(): void
    {
        $this->assertSame(
            ['start' => '2026-07-01', 'end' => '2026-09-30'],
            BillingInterval::Quarterly->cycleContaining(CarbonImmutable::parse('2026-08-15'))->toArray(),
        );
        $this->assertSame(365, BillingInterval::Yearly->cycleContaining(CarbonImmutable::parse('2026-08-15'))->days());
        $this->assertSame(366, BillingInterval::Yearly->cycleContaining(CarbonImmutable::parse('2028-08-15'))->days());
    }

    #[Test]
    public function period_overlap(): void
    {
        $september = new Period('2026-09-01', '2026-09-30');

        $this->assertSame(['start' => '2026-09-20', 'end' => '2026-09-30'], $september->overlap(new Period('2026-09-20', '2026-10-15'))->toArray());
        $this->assertNull($september->overlap(new Period('2026-10-01', '2026-10-31')));
        $this->assertSame(1, $september->overlap(new Period('2026-08-01', '2026-09-01'))->days());
    }

    #[Test]
    public function a_period_cannot_end_before_it_starts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Period('2026-09-10', '2026-09-09');
    }
}
