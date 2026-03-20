<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Block;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Internal\Algebra;
use Psalm\Internal\Algebra\Formula_Generator;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Clause;
use Psalm\Internal\Scope\Loop_Scope;
use Psalm\Type\Reconciler;
use UnexpectedValueException;
use function array_diff;
use function array_filter;
use function array_keys;
use function array_values;
use function in_array;
use function preg_match;
use function preg_quote;
use function spl_object_id;
/**
 * @internal
 */
final class Do_Analyzer
{
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Stmt\Do_ $stmt, Context $context): ?bool
    {
        $do_context = clone $context;
        $do_context->break_types[] = 'loop';
        $do_context->inside_loop = true;
        $codebase = $statements_analyzer->get_codebase();
        if ($codebase->alter_code && $do_context->branch_point === null) {
            $do_context->branch_point = (int) $stmt->get_attribute('startFilePos');
        }
        $loop_scope = new Loop_Scope($do_context, $context);
        $loop_scope->protected_var_ids = $context->protected_var_ids;
        self::analyze_do_naively($statements_analyzer, $stmt, $do_context, $loop_scope);
        $mixed_var_ids = [];
        foreach ($do_context->vars_in_scope as $var_id => $type) {
            if ($type->has_mixed()) {
                $mixed_var_ids[] = $var_id;
            }
        }
        $cond_id = spl_object_id($stmt->cond);
        $while_clauses = Formula_Generator::get_formula($cond_id, $cond_id, $stmt->cond, $context->self, $statements_analyzer, $codebase);
        $while_clauses = array_values(array_filter($while_clauses, static function (Clause $c) use ($mixed_var_ids): bool {
            $keys = array_keys($c->possibilities);
            $mixed_var_ids = array_diff($mixed_var_ids, $keys);
            foreach ($keys as $key) {
                foreach ($mixed_var_ids as $mixed_var_id) {
                    if (preg_match('/^' . preg_quote($mixed_var_id, '/') . '(\[|-)/', $key)) {
                        return false;
                    }
                }
            }
            return true;
        }));
        if (!$while_clauses) {
            $while_clauses = [new Clause([], $cond_id, $cond_id, true)];
        }
        if (Loop_Analyzer::analyze($statements_analyzer, $stmt->stmts, While_Analyzer::get_and_expressions($stmt->cond), [], $loop_scope, $inner_loop_context, true, true) === false) {
            return false;
        }
        // because it's a do {} while, inner loop vars belong to the main context
        if (!$inner_loop_context) {
            throw new UnexpectedValueException('There should be an inner loop context');
        }
        $negated_while_clauses = Algebra::negate_formula($while_clauses);
        $negated_while_types = Algebra::get_truths_from_formula(Algebra::simplify_cnf([...$context->clauses, ...$negated_while_clauses]));
        if ($negated_while_types) {
            $changed_var_ids = [];
            [$inner_loop_context->vars_in_scope, $inner_loop_context->references_in_scope] = Reconciler::reconcile_keyed_types($negated_while_types, [], $inner_loop_context->vars_in_scope, $inner_loop_context->references_in_scope, $changed_var_ids, [], $statements_analyzer, [], true, new Code_Location($statements_analyzer->get_source(), $stmt->cond));
        }
        Loop_Analyzer::set_loop_vars($inner_loop_context, $context, $loop_scope);
        $do_context->loop_scope = null;
        $context->vars_possibly_in_scope = [...$context->vars_possibly_in_scope, ...$do_context->vars_possibly_in_scope];
        if ($context->collect_exceptions) {
            $context->merge_exceptions($inner_loop_context);
        }
        return null;
    }
    private static function analyze_do_naively(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Stmt\Do_ $stmt, Context $context, Loop_Scope $loop_scope): void
    {
        $do_context = clone $context;
        $suppressed_issues = $statements_analyzer->get_suppressed_issues();
        if (!in_array('RedundantCondition', $suppressed_issues, true)) {
            $statements_analyzer->add_suppressed_issues(['RedundantCondition']);
        }
        if (!in_array('RedundantConditionGivenDocblockType', $suppressed_issues, true)) {
            $statements_analyzer->add_suppressed_issues(['RedundantConditionGivenDocblockType']);
        }
        if (!in_array('TypeDoesNotContainType', $suppressed_issues, true)) {
            $statements_analyzer->add_suppressed_issues(['TypeDoesNotContainType']);
        }
        $do_context->loop_scope = $loop_scope;
        $statements_analyzer->analyze($stmt->stmts, $do_context);
        if (!in_array('RedundantCondition', $suppressed_issues, true)) {
            $statements_analyzer->remove_suppressed_issues(['RedundantCondition']);
        }
        if (!in_array('RedundantConditionGivenDocblockType', $suppressed_issues, true)) {
            $statements_analyzer->remove_suppressed_issues(['RedundantConditionGivenDocblockType']);
        }
        if (!in_array('TypeDoesNotContainType', $suppressed_issues, true)) {
            $statements_analyzer->remove_suppressed_issues(['TypeDoesNotContainType']);
        }
    }
}