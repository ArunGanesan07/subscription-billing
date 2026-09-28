<?php

namespace App\Enums;

use App\Billing\Period;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Billing cycles are calendar-aligned: a monthly plan bills 1st–last of the
 * month, whatever day the customer subscribed. A mid-cycle start is handled by
 * proration rather than by shifting the cycle anchor.
 */
enum BillingInterval: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';

    public function cycleContaining(CarbonInterface $date): Period
    {
        $date = CarbonImmutable::parse($date)->startOfDay();

        return match ($this) {
            self::Monthly => new Period($date->startOfMonth(), $date->endOfMonth()->startOfDay()),
            self::Quarterly => new Period($date->startOfQuarter(), $date->endOfQuarter()->startOfDay()),
            self::Yearly => new Period($date->startOfYear(), $date->endOfYear()->startOfDay()),
        };
    }
}
