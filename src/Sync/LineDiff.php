<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Sync;

/** Minimal LCS line diff for dry-run summaries. */
final class LineDiff
{
    /**
     * @return list<array{0: '+'|'-'|' ', 1: string}>
     */
    public static function diff(string $old, string $new): array
    {
        $a = $old === '' ? [] : explode("\n", $old);
        $b = $new === '' ? [] : explode("\n", $new);

        $prefix = 0;
        $max = min(count($a), count($b));
        while ($prefix < $max && $a[$prefix] === $b[$prefix]) {
            ++$prefix;
        }
        $suffix = 0;
        while ($suffix < $max - $prefix && $a[count($a) - 1 - $suffix] === $b[count($b) - 1 - $suffix]) {
            ++$suffix;
        }

        $midA = array_slice($a, $prefix, count($a) - $prefix - $suffix);
        $midB = array_slice($b, $prefix, count($b) - $prefix - $suffix);
        $n = count($midA);
        $m = count($midB);

        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; --$i) {
            for ($j = $m - 1; $j >= 0; --$j) {
                $lcs[$i][$j] = $midA[$i] === $midB[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        $ops = [];
        foreach (array_slice($a, 0, $prefix) as $line) {
            $ops[] = [' ', $line];
        }
        $i = $j = 0;
        while ($i < $n && $j < $m) {
            if ($midA[$i] === $midB[$j]) {
                $ops[] = [' ', $midA[$i]];
                ++$i;
                ++$j;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $ops[] = ['-', $midA[$i++]];
            } else {
                $ops[] = ['+', $midB[$j++]];
            }
        }
        while ($i < $n) {
            $ops[] = ['-', $midA[$i++]];
        }
        while ($j < $m) {
            $ops[] = ['+', $midB[$j++]];
        }
        foreach (array_slice($a, count($a) - $suffix) as $line) {
            $ops[] = [' ', $line];
        }

        return $ops;
    }
}
