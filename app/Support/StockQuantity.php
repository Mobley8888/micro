<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

class StockQuantity
{
    public static function toMilli(string|int $value): int
    {
        if (preg_match('/^(\d+)(?:\.(\d{1,3}))?$/', (string) $value, $matches) !== 1) {
            throw ValidationException::withMessages(['quantity' => 'La quantité doit être positive et comporter au plus trois décimales.']);
        }

        $whole = (int) $matches[1];
        if ($whole > intdiv(PHP_INT_MAX - 999, 1000)) {
            throw ValidationException::withMessages(['quantity' => 'La quantité dépasse la limite autorisée.']);
        }

        return $whole * 1000 + (int) str_pad($matches[2] ?? '', 3, '0');
    }

    public static function toSignedMilli(string|int $value): int
    {
        $string = (string) $value;
        $negative = str_starts_with($string, '-');
        $absolute = $negative ? substr($string, 1) : $string;
        $milli = self::toMilli($absolute);

        return $negative ? -$milli : $milli;
    }

    public static function fromMilli(int $milli): string
    {
        $whole = intdiv(abs($milli), 1000);
        $fraction = str_pad((string) (abs($milli) % 1000), 3, '0', STR_PAD_LEFT);

        return ($milli < 0 ? '-' : '').$whole.'.'.$fraction;
    }

    public static function costToScale(string|int $value): int
    {
        if (preg_match('/^(\d+)(?:\.(\d{1,4}))?$/', (string) $value, $matches) !== 1) {
            throw ValidationException::withMessages(['unit_cost' => 'Le coût unitaire doit être positif et comporter au plus quatre décimales.']);
        }
        $whole = (int) $matches[1];
        if ($whole > intdiv(PHP_INT_MAX - 9999, 10000)) {
            throw ValidationException::withMessages(['unit_cost' => 'Le coût unitaire dépasse la limite autorisée.']);
        }

        return $whole * 10000 + (int) str_pad($matches[2] ?? '', 4, '0');
    }

    public static function costFromScale(int $scaled): string
    {
        $whole = intdiv(abs($scaled), 10000);
        $fraction = str_pad((string) (abs($scaled) % 10000), 4, '0', STR_PAD_LEFT);

        return ($scaled < 0 ? '-' : '').$whole.'.'.$fraction;
    }

    public static function lineValueCents(int $quantityMilli, int $unitCostScaled): int
    {
        if ($unitCostScaled > 0 && $quantityMilli > intdiv(PHP_INT_MAX - 50000, $unitCostScaled)) {
            throw ValidationException::withMessages(['quantity' => 'La valeur de cette ligne dépasse la limite autorisée.']);
        }

        return intdiv($quantityMilli * $unitCostScaled + 50000, 100000);
    }

    public static function averageCostScale(int $totalValueCents, int $quantityMilli): int
    {
        if ($quantityMilli <= 0) {
            return 0;
        }
        if ($totalValueCents > intdiv(PHP_INT_MAX - intdiv($quantityMilli, 2), 100000)) {
            throw ValidationException::withMessages(['quantity' => 'La valorisation dépasse la limite de calcul autorisée.']);
        }

        return intdiv($totalValueCents * 100000 + intdiv($quantityMilli, 2), $quantityMilli);
    }

    public static function percentageBasisPoints(string|int $value): int
    {
        if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', (string) $value, $matches) !== 1) {
            throw ValidationException::withMessages(['tax_rate' => 'Le taux de taxe est invalide.']);
        }

        return (int) $matches[1] * 100 + (int) str_pad($matches[2] ?? '', 2, '0');
    }
}
