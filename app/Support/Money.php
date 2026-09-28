<?php

namespace App\Support;

use Illuminate\Support\Number;

final class Money
{
    /**
     * @return array{amount: int, currency: string, formatted: string}
     */
    public static function toArray(int $minorUnits, string $currency): array
    {
        return [
            'amount' => $minorUnits,
            'currency' => $currency,
            'formatted' => self::format($minorUnits, $currency),
        ];
    }

    public static function format(int $minorUnits, string $currency): string
    {
        return Number::currency($minorUnits / 100, in: $currency, locale: $currency === 'INR' ? 'en_IN' : 'en');
    }
}
