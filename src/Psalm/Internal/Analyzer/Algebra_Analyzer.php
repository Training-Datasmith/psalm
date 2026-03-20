<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Exception\Complicated_Expression_Exception;
use Psalm\Internal\Algebra;
use Psalm\Internal\Clause;
use Psalm\Issue\Paradoxical_Condition;
use Psalm\Issue\Redundant_Condition;
use Psalm\Issue_Buffer;
use Psalm\Storage\Assertion\In_Array;
use Psalm\Storage\Assertion\Not_In_Array;
use function array_intersect_key;
use function count;
use function implode;
/**
 * @internal
 */
final class Algebra_Analyzer
{
    /**
     * This looks to see if there are any clauses in one formula that contradict
     * clauses in another formula, or clauses that duplicate previous clauses
     *
     * e.g.
     * if ($a) { }
     * elseif ($a) { }
     *
     * @param  list<Clause>   $formula_1
     * @param  list<Clause>   $formula_2
     * @param  array<string, int>  $new_assigned_var_ids
     */
    public static function check_for_paradox(array $formula_1, array $formula_2, Statements_Analyzer $statements_analyzer, Php_Parser\Node $stmt, array $new_assigned_var_ids): void
    {
        try {
            $negated_formula2 = Algebra::negate_formula($formula_2);
        } catch (Complicated_Expression_Exception) {
            return;
        }
        $formula_1_hashes = [];
        foreach ($formula_1 as $formula_1_clause) {
            $formula_1_hashes[$formula_1_clause->hash] = true;
        }
        $formula_2_hashes = [];
        foreach ($formula_2 as $formula_2_clause) {
            $hash = $formula_2_clause->hash;
            if (!$formula_2_clause->generated && !$formula_2_clause->wedge && $formula_2_clause->reconcilable && (isset($formula_1_hashes[$hash]) || isset($formula_2_hashes[$hash])) && !array_intersect_key($new_assigned_var_ids, $formula_2_clause->possibilities)) {
                Issue_Buffer::maybe_add(new Redundant_Condition($formula_2_clause . ' has already been asserted', new Code_Location($statements_analyzer, $stmt), 'already asserted ' . $formula_2_clause), $statements_analyzer->get_suppressed_issues());
            }
            $formula_2_hashes[$hash] = true;
        }
        // remove impossible types
        foreach ($negated_formula2 as $negated_clause_2) {
            if (!$negated_clause_2->reconcilable) {
                continue;
            }
            if ($negated_clause_2->wedge) {
                continue;
            }
            foreach ($formula_1 as $clause_1) {
                if ($negated_clause_2 === $clause_1) {
                    continue;
                }
                if (!$clause_1->reconcilable) {
                    continue;
                }
                if ($clause_1->wedge) {
                    continue;
                }
                $negated_clause_2_contains_1_possibilities = true;
                foreach ($clause_1->possibilities as $key => $keyed_possibilities) {
                    if (!isset($negated_clause_2->possibilities[$key])) {
                        $negated_clause_2_contains_1_possibilities = false;
                        break;
                    }
                    if ($negated_clause_2->possibilities[$key] != $keyed_possibilities) {
                        $negated_clause_2_contains_1_possibilities = false;
                        break;
                    }
                    foreach ($keyed_possibilities as $possibility) {
                        if ($possibility instanceof In_Array || $possibility instanceof Not_In_Array) {
                            $negated_clause_2_contains_1_possibilities = false;
                            break;
                        }
                    }
                }
                if ($negated_clause_2_contains_1_possibilities) {
                    $mini_formula_2 = Algebra::negate_formula([$negated_clause_2]);
                    if (!$mini_formula_2[0]->wedge) {
                        if (count($mini_formula_2) > 1) {
                            $paradox_message = 'Condition ((' . implode(') && (', $mini_formula_2) . '))' . ' contradicts a previously-established condition (' . $clause_1 . ')';
                        } else {
                            $paradox_message = 'Condition (' . $mini_formula_2[0] . ')' . ' contradicts a previously-established condition (' . $clause_1 . ')';
                        }
                    } else {
                        $paradox_message = 'Condition not(' . $negated_clause_2 . ')' . ' contradicts a previously-established condition (' . $clause_1 . ')';
                    }
                    Issue_Buffer::maybe_add(new Paradoxical_Condition($paradox_message, new Code_Location($statements_analyzer, $stmt)), $statements_analyzer->get_suppressed_issues());
                    return;
                }
            }
        }
    }
}