<?php

namespace App\Services;

use InvalidArgumentException;

/** Money helpers. All amounts are integer poisha (1 BDT = 100 poisha). Only place money arithmetic happens. */
final class Money
{
    private const BN_DIGITS = '০১২৩৪৫৬৭৮৯';

    public static function assertPoisha(mixed $v, string $label = 'amount'): int
    {
        if (! is_int($v)) throw new InvalidArgumentException("$label must be an integer");
        if ($v < 0) throw new InvalidArgumentException("$label must not be negative, got $v");
        return $v;
    }

    /** @param int[] $values */
    public static function sum(array $values): int
    {
        $t = 0;
        foreach ($values as $v) {
            if (! is_int($v)) throw new InvalidArgumentException('amount must be an integer');
            $t += $v;
        }
        return $t;
    }

    public static function multiply(int $unit, int $qty): int
    {
        self::assertPoisha($unit, 'unit');
        self::assertPoisha($qty, 'quantity');
        $r = $unit * $qty;
        if (! is_int($r)) throw new InvalidArgumentException('product overflow');
        return $r;
    }

    /** Percentage in basis points (1% = 100 bp), rounded half up. Integer arithmetic. */
    public static function percentOfBp(int $amount, int $bp): int
    {
        self::assertPoisha($amount);
        self::assertPoisha($bp, 'basisPoints');
        if ($bp > 10000) throw new InvalidArgumentException('basisPoints must be <= 10000');
        return intdiv($amount * $bp + 5000, 10000);
    }

    public static function toLatinDigits(string $s): string
    {
        return preg_replace_callback('/[০-৯]/u', fn ($m) => (string) (mb_strpos(self::BN_DIGITS, $m[0])), $s);
    }

    /** "120", "120.5", "1,250.75", "৳১২০" -> poisha, no floats. */
    public static function parseTaka(string $input): int
    {
        $c = preg_replace('/[,\s৳]/u', '', self::toLatinDigits($input));
        if (! preg_match('/^(\d{1,9})(?:\.(\d{1,2}))?$/', $c, $m)) {
            throw new InvalidArgumentException("Invalid taka amount: \"$input\"");
        }
        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }

    /** 125050 -> "৳১,২৫০.৫০" (bn) or "৳1,250.50" (en); lakh/crore grouping; decimals only when non-zero. */
    public static function formatTaka(int $amount, string $locale = 'bn'): string
    {
        $neg = $amount < 0;
        $abs = abs($amount);
        $whole = intdiv($abs, 100);
        $frac = $abs % 100;
        $s = (string) $whole;
        if (strlen($s) > 3) {
            $s = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($s, 0, -3)).','.substr($s, -3);
        }
        if ($frac !== 0) $s .= '.'.str_pad((string) $frac, 2, '0', STR_PAD_LEFT);
        if ($locale === 'bn') {
            $s = preg_replace_callback('/\d/', fn ($m) => mb_substr(self::BN_DIGITS, (int) $m[0], 1), $s);
        }
        return ($neg ? '-' : '').'৳'.$s;
    }

    /**
     * Largest-remainder split: parts always sum exactly to $total.
     * @param int[] $weights
     * @return int[]
     */
    public static function allocateProportionally(int $total, array $weights): array
    {
        self::assertPoisha($total, 'total');
        foreach ($weights as $w) self::assertPoisha($w, 'weight');
        if ($weights === []) {
            if ($total !== 0) throw new InvalidArgumentException('Cannot allocate a non-zero total to no weights');
            return [];
        }
        $wsum = self::sum($weights);
        if ($wsum === 0) {
            if ($total !== 0) throw new InvalidArgumentException('Cannot allocate a non-zero total to zero weights');
            return array_fill(0, count($weights), 0);
        }
        $base = array_map(fn ($w) => intdiv($total * $w, $wsum), $weights);
        $rem = [];
        foreach ($weights as $i => $w) $rem[] = ['i' => $i, 'r' => ($total * $w) % $wsum];
        usort($rem, fn ($a, $b) => [$b['r'], $a['i']] <=> [$a['r'], $b['i']]);
        $left = $total - self::sum($base);
        foreach ($rem as $r) {
            if ($left === 0) break;
            $base[$r['i']]++;
            $left--;
        }
        return $base;
    }
}
