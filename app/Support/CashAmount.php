<?php

namespace App\Support;

use InvalidArgumentException;

final class CashAmount
{
    public static function toCents(string|int $amount): int
    {
        if (preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', (string) $amount, $matches) !== 1) {
            throw new InvalidArgumentException('Montant de caisse invalide.');
        }

        $cents = ((int) $matches[2] * 100) + (int) str_pad($matches[3] ?? '', 2, '0');

        return ($matches[1] ?? '') === '-' ? -$cents : $cents;
    }

    public static function format(string|int $amount): string
    {
        $cents = self::toCents($amount);
        $absolute = abs($cents);
        $whole = (string) intdiv($absolute, 100);
        $groupedWhole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ' ', $whole);
        $fraction = str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);

        return ($cents < 0 ? '-' : '').$groupedWhole.','.$fraction;
    }

    public static function fromCents(int $cents): string
    {
        $absolute = abs($cents);
        $formatted = intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);

        return $cents < 0 ? '-'.$formatted : $formatted;
    }
}
