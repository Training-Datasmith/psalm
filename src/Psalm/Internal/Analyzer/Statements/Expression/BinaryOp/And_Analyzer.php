<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression\Binary_Op;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Internal\Algebra;
use Psalm\Internal\Algebra\Formula_Generator;
use Psalm\Internal\Analyzer\Statements\Block\If_Conditional_Analyzer;
use Psalm\Internal\Analyzer\Statements\Block\If_Else_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Clause;
use Psalm\Node\Stmt\Virtual_Expression;
use Psalm\Node\Stmt\Virtual_If;
use Psalm\Type\Reconciler;
use function array_diff_key;
use function array_filter;
use function array_map;
use function array_merge;
use function array_values;
use function count;
use function in_array;
use function spl_object_id;
/**
 * @internal
 */
final class And_Analyzer
{
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Binary_Op $stmt, Context $context, bool $from_stmt = false): bool
    {
        if ($from_stmt) {
            $fake_if_stmt = new Virtual_If($stmt->left, ['stmts' => [new Virtual_Expression($stmt->right)]], $stmt->get_attributes());
            return If_Else_Analyzer::analyze($statements_analyzer, $fake_if_stmt, $context) !== false;
        }
        $pre_referenced_var_ids = $context->cond_referenced_var_ids;
        $pre_assigned_var_ids = $context->assigned_var_ids;
        $left_context = clone $context;
        $left_context->cond_referenced_var_ids = [];
        $left_context->assigned_var_ids = [];
        /** @var list<string> $left_context->reconciled_expression_clauses */
        $left_context->reconciled_expression_clauses = [];
        if (Expression_Analyzer::analyze($statements_analyzer, $stmt->left, $left_context) === false) {
            return false;
        }
        If_Conditional_Analyzer::handle_paradoxical_condition($statements_analyzer, $stmt->left);
        $codebase = $statements_analyzer->get_codebase();
        $left_cond_id = spl_object_id($stmt->left);
        $left_clauses = Formula_Generator::get_formula($left_cond_id, $left_cond_id, $stmt->left, $context->self, $statements_analyzer, $codebase);
        foreach ($left_context->vars_in_scope as $var_id => $type) {
            if (isset($left_context->assigned_var_ids[$var_id])) {
                $context->vars_in_scope[$var_id] = $type;
            }
        }
        /** @var array<string, bool> */
        $left_referenced_var_ids = $left_context->cond_referenced_var_ids;
        $context->cond_referenced_var_ids = array_merge($pre_referenced_var_ids, $left_referenced_var_ids);
        $left_assigned_var_ids = array_diff_key($left_context->assigned_var_ids, $pre_assigned_var_ids);
        $left_referenced_var_ids = array_diff_key($left_referenced_var_ids, $left_assigned_var_ids);
        $context_clauses = array_merge($left_context->clauses, $left_clauses);
        if ($left_context->reconciled_expression_clauses) {
            $reconciled_expression_clauses = $left_context->reconciled_expression_clauses;
            $context_clauses = array_values(array_filter($context_clauses, static fn(Clause $c): bool => !in_array($c->hash, $reconciled_expression_clauses, true)));
            if (count($context_clauses) === 1 && $context_clauses[0]->wedge && !$context_clauses[0]->possibilities) {
                $context_clauses = [];
            }
        }
        $simplified_clauses = Algebra::simplify_cnf($context_clauses);
        $active_left_assertions = [];
        $left_type_assertions = Algebra::get_truths_from_formula($simplified_clauses, $left_cond_id, $left_referenced_var_ids, $active_left_assertions);
        $changed_var_ids = [];
        if ($left_type_assertions) {
            $right_context = clone $context;
            // while in an and, we allow scope to boil over to support
            // statements of the form if ($x && $x->foo())
            [$right_context->vars_in_scope, $right_context->references_in_scope] = Reconciler::reconcile_keyed_types($left_type_assertions, $active_left_assertions, $right_context->vars_in_scope, $context->references_in_scope, $changed_var_ids, $left_referenced_var_ids, $statements_analyzer, $statements_analyzer->get_template_type_map() ?: [], $context->inside_loop, new Code_Location($statements_analyzer->get_source(), $stmt->left), $context->inside_negation);
        } else {
            $right_context = clone $left_context;
        }
        $partitioned_clauses = Context::remove_reconciled_clauses([...$left_context->clauses, ...$left_clauses], $changed_var_ids);
        $right_context->clauses = $partitioned_clauses[0];
        if (Expression_Analyzer::analyze($statements_analyzer, $stmt->right, $right_context) === false) {
            return false;
        }
        If_Conditional_Analyzer::handle_paradoxical_condition($statements_analyzer, $stmt->right);
        $context->cond_referenced_var_ids = array_merge($right_context->cond_referenced_var_ids, $left_context->cond_referenced_var_ids);
        if ($context->inside_conditional) {
            $context->update_checks($right_context);
            $context->vars_possibly_in_scope = array_merge($right_context->vars_possibly_in_scope, $left_context->vars_possibly_in_scope);
            $context->assigned_var_ids = array_merge($left_context->assigned_var_ids, $right_context->assigned_var_ids);
        }
        if ($context->if_body_context && !$context->inside_negation) {
            $if_body_context = $context->if_body_context;
            $context->vars_in_scope = $right_context->vars_in_scope;
            $if_body_context->vars_in_scope = [...$if_body_context->vars_in_scope, ...$context->vars_in_scope];
            $if_body_context->cond_referenced_var_ids = [...$if_body_context->cond_referenced_var_ids, ...$context->cond_referenced_var_ids];
            $if_body_context->assigned_var_ids = [...$if_body_context->assigned_var_ids, ...$context->assigned_var_ids];
            $if_body_context->reconciled_expression_clauses = [...$if_body_context->reconciled_expression_clauses, ...array_map(
                /** @return string|int */
                static fn(Clause $c): string => $c->hash,
                $partitioned_clauses[1]
            )];
            $if_body_context->vars_possibly_in_scope = [...$if_body_context->vars_possibly_in_scope, ...$context->vars_possibly_in_scope];
            $if_body_context->update_checks($context);
        } else {
            $context->vars_in_scope = $left_context->vars_in_scope;
        }
        return true;
    }
}