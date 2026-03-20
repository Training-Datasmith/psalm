<?php

declare (strict_types=1);
namespace Psalm\Internal\Diff;

use Closure;
use Exception;
use Php_Parser\Node\Stmt;
use function array_reverse;
use function count;
/**
 * Borrows from https://github.com/nikic/PHP-Parser/blob/master/lib/PhpParser/Internal/Differ.php
 *
 * Implements the Myers diff algorithm.
 *
 * Myers, Eugene W. "An O (ND) difference algorithm and its variations."
 * Algorithmica 1.1 (1986): 251-266.
 *
 * @internal
 */
abstract class Ast_Differ
{
    /**
     * @param Closure(Stmt, Stmt, string, string, bool=): bool $is_equal
     * @param array<int, Stmt> $a
     * @param array<int, Stmt> $b
     * @return array{0:non-empty-list<array<int, int>>, 1: int, 2: int, 3: array<int, bool>}
     */
    protected static function calculate_trace(Closure $is_equal, array $a, array $b, string $a_code, string $b_code): array
    {
        $n = count($a);
        $m = count($b);
        $max = $n + $m;
        /** @var array<int, int> $v */
        $v = [1 => 0];
        $bc = [];
        $trace = [];
        for ($d = 0; $d <= $max; ++$d) {
            $trace[] = $v;
            for ($k = -$d; $k <= $d; $k += 2) {
                if ($k === -$d || $k !== $d && $v[$k - 1] < $v[$k + 1]) {
                    $x = $v[$k + 1];
                } else {
                    $x = $v[$k - 1] + 1;
                }
                $y = $x - $k;
                $body_change = false;
                while ($x < $n && $y < $m && $is_equal($a[$x], $b[$y], $a_code, $b_code, $body_change)) {
                    $bc[$x] = $body_change;
                    ++$x;
                    ++$y;
                    $body_change = false;
                }
                $v[$k] = $x;
                if ($x >= $n && $y >= $m) {
                    return [$trace, $x, $y, $bc];
                }
            }
        }
        throw new Exception('Should not happen');
    }
    /**
     * @param array<int, array<int, int>> $trace
     * @param array<int, Stmt> $a
     * @param array<int, Stmt> $b
     * @param array<int, bool> $bc
     * @return list<DiffElem>
     * @psalm-pure
     */
    protected static function extract_diff(array $trace, int $x, int $y, array $a, array $b, array $bc): array
    {
        $result = [];
        for ($d = count($trace) - 1; $d >= 0; --$d) {
            $v = $trace[$d];
            $k = $x - $y;
            if ($k === -$d || $k !== $d && $v[$k - 1] < $v[$k + 1]) {
                $prev_k = $k + 1;
            } else {
                $prev_k = $k - 1;
            }
            $prev_x = $v[$prev_k];
            $prev_y = $prev_x - $prev_k;
            while ($x > $prev_x && $y > $prev_y) {
                $result[] = new Diff_Elem($bc[$x - 1] ? Diff_Elem::TYPE_KEEP_SIGNATURE : Diff_Elem::TYPE_KEEP, $a[$x - 1], $b[$y - 1]);
                --$x;
                --$y;
            }
            if ($d === 0) {
                break;
            }
            while ($x > $prev_x) {
                $result[] = new Diff_Elem(Diff_Elem::TYPE_REMOVE, $a[$x - 1], null);
                --$x;
            }
            while ($y > $prev_y) {
                $result[] = new Diff_Elem(Diff_Elem::TYPE_ADD, null, $b[$y - 1]);
                --$y;
            }
        }
        return array_reverse($result);
    }
}