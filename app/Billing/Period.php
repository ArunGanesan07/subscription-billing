<?php

namespace App\Billing;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * An inclusive range of whole days. Billing works in days because usage is
 * recorded per day; there is no time-of-day component anywhere in billing.
 */
final readonly class Period
{
    public CarbonImmutable $start;

    public CarbonImmutable $end;

    public function __construct(CarbonInterface|string $start, CarbonInterface|string $end)
    {
        $this->start = CarbonImmutable::parse($start)->startOfDay();
        $this->end = CarbonImmutable::parse($end)->startOfDay();

        if ($this->end->lessThan($this->start)) {
            throw new InvalidArgumentException("Period end {$this->end->toDateString()} is before start {$this->start->toDateString()}.");
        }
    }

    public function days(): int
    {
        return (int) $this->start->diffInDays($this->end) + 1;
    }

    public function contains(CarbonInterface|string $date): bool
    {
        $date = CarbonImmutable::parse($date)->startOfDay();

        return $date->betweenIncluded($this->start, $this->end);
    }

    public function overlap(Period $other): ?Period
    {
        $start = $this->start->max($other->start);
        $end = $this->end->min($other->end);

        return $start->lessThanOrEqualTo($end) ? new Period($start, $end) : null;
    }

    public function equals(Period $other): bool
    {
        return $this->start->equalTo($other->start) && $this->end->equalTo($other->end);
    }

    /**
     * @return list<string> every date in the period as Y-m-d
     */
    public function dates(): array
    {
        $dates = [];
        for ($day = $this->start; $day->lessThanOrEqualTo($this->end); $day = $day->addDay()) {
            $dates[] = $day->toDateString();
        }

        return $dates;
    }

    public function label(): string
    {
        return $this->start->format('d M Y').' – '.$this->end->format('d M Y');
    }

    public function toArray(): array
    {
        return ['start' => $this->start->toDateString(), 'end' => $this->end->toDateString()];
    }
}
