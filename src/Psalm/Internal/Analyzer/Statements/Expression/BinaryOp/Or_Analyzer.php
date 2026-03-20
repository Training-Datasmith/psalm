<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression\Binary_Op;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Exception\Complicated_Expression_Exception;
use Psalm\Exception\Scope_Analysis_Exception;
use Psalm\Internal\Algebra;
use Psalm\Internal\Algebra\Formula_Generator;
use Psalm\Internal\Analyzer\Statements\Block\If_Conditional_Analyzer;
use Psalm\Internal\Analyzer\Statements\Block\If_Else\If_Analyzer;
use Psalm\Internal\Analyzer\Statements\Block\If_Else_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Expression_Identifier;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Clause;
use Psalm\Internal\Scope\If_Scope;
use Psalm\Internal\Type\Assertion_Reconciler;
use Psalm\Node\Expr\Virtual_Boolean_Not;
use Psalm\Node\Stmt\Virtual_Expression;
use Psalm\Node\Stmt\Virtual_If;
use Psalm\Storage\Assertion\Truthy;
use Psalm\Type;
use Psalm\Type\Reconciler;
use function array_diff_key;
use function array_filter;
use function array_merge;
use function array_values;
use function count;
use function in_array;
use function spl_object_id;
/**
 * @internal
 */
final class Or_Analyzer
{
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Binary_Op $stmt, Context $context, bool $from_stmt = false): bool
    {
        if ($from_stmt) {
            $fake_if_stmt = new Virtual_If(new Virtual_Boolean_Not($stmt->left, $stmt->left->get_attributes()), ['stmts' => [new Virtual_Expression($stmt->right)]], $stmt->get_attributes());
            return If_Else_Analyzer::analyze($statements_analyzer, $fake_if_stmt, $context) !== false;
        }
        $codebase = $statements_analyzer->get_codebase();
        $post_leaving_if_context = null;
        // we cap this at max depth of 4 to prevent quadratic behaviour
        // when analysing <expr> || <expr> || <expr> || <expr> || <expr>
        if (!$stmt->left instanceof Php_Parser\Node\Expr\Binary_Op\Boolean_Or || !$stmt->left->left instanceof Php_Parser\Node\Expr\Binary_Op\Boolean_Or || !$stmt->left->left->left instanceof Php_Parser\Node\Expr\Binary_Op\Boolean_Or) {
            $if_scope = new If_Scope();
            try {
                $if_conditional_scope = If_Conditional_Analyzer::analyze($statements_analyzer, $stmt->left, $context, $codebase, $if_scope, $context->branch_point ?: (int) $stmt->get_attribute('startFilePos'));
                $left_context = $if_conditional_scope->if_context;
                $left_referenced_var_ids = $if_conditional_scope->cond_referenced_var_ids;
                $left_assigned_var_ids = $if_conditional_scope->assigned_in_conditional_var_ids;
                if ($stmt->left instanceof Php_Parser\Node\Expr\Binary_Op\Boolean_Or) {
                    $post_leaving_if_context = clone $context;
                }
            } catch (Scope_Analysis_Exception) {
                return false;
            }
        } else {
            $pre_referenced_var_ids = $context->cond_referenced_var_ids;
            $context->cond_referenced_var_ids = [];
            $pre_assigned_var_ids = $context->assigned_var_ids;
            $post_leaving_if_context = clone $context;
            $left_context = clone $context;
            $left_context->if_body_context = null;
            $left_context->assigned_var_ids = [];
            if (Expression_Analyzer::analyze($statements_analyzer, $stmt->left, $left_context) === false) {
                return false;
            }
            foreach ($left_context->parent_remove_vars as $var_id => $_) {
                $context->remove_var_from_conflicting_clauses($var_id);
            }
            If_Conditional_Analyzer::handle_paradoxical_condition($statements_analyzer, $stmt->left);
            foreach ($left_context->vars_in_scope as $var_id => $type) {
                if (!isset($context->vars_in_scope[$var_id])) {
                    if (isset($left_context->assigned_var_ids[$var_id])) {
                        $context->vars_in_scope[$var_id] = $type;
                    }
                } else {
                    $context->vars_in_scope[$var_id] = Type::combine_union_types($context->vars_in_scope[$var_id], $type, $codebase);
                }
            }
            $left_referenced_var_ids = $left_context->cond_referenced_var_ids;
            $left_context->cond_referenced_var_ids = [...$pre_referenced_var_ids, ...$left_referenced_var_ids];
            $left_assigned_var_ids = array_diff_key($left_context->assigned_var_ids, $pre_assigned_var_ids);
            $left_context->assigned_var_ids = [...$pre_assigned_var_ids, ...$left_context->assigned_var_ids];
            $left_referenced_var_ids = array_diff_key($left_referenced_var_ids, $left_assigned_var_ids);
        }
        $left_cond_id = spl_object_id($stmt->left);
        $left_clauses = Formula_Generator::get_formula($left_cond_id, $left_cond_id, $stmt->left, $context->self, $statements_analyzer, $codebase);
        try {
            $negated_left_clauses = Algebra::negate_formula($left_clauses);
        } catch (Complicated_Expression_Exception) {
            try {
                $negated_left_clauses = Formula_Generator::get_formula($left_cond_id, $left_cond_id, new Virtual_Boolean_Not($stmt->left), $context->self, $statements_analyzer, $codebase, false);
            } catch (Complicated_Expression_Exception) {
                return false;
            }
        }
        if ($left_context->reconciled_expression_clauses) {
            $reconciled_expression_clauses = $left_context->reconciled_expression_clauses;
            $negated_left_clauses = array_values(array_filter($negated_left_clauses, static fn(Clause $c): bool => !in_array($c->hash, $reconciled_expression_clauses)));
            if (count($negated_left_clauses) === 1 && $negated_left_clauses[0]->wedge && !$negated_left_clauses[0]->possibilities) {
                $negated_left_clauses = [];
            }
        }
        $clauses_for_right_analysis = Algebra::simplify_cnf([...$context->clauses, ...$negated_left_clauses]);
        $active_negated_type_assertions = [];
        $negated_type_assertions = Algebra::get_truths_from_formula($clauses_for_right_analysis, $left_cond_id, $left_referenced_var_ids, $active_negated_type_assertions);
        $changed_var_ids = [];
        $right_context = clone $context;
        if ($stmt->left instanceof Php_Parser\Node\Expr\Binary_Op\Boolean_Or && $left_assigned_var_ids && $post_leaving_if_context) {
            If_Analyzer::add_conditionally_assigned_vars_to_context($statements_analyzer, $stmt->left, $post_leaving_if_context, $right_context, $left_assigned_var_ids);
        }
        if ($negated_type_assertions) {
            // while in an or, we allow scope to boil over to support
            // statements of the form if ($x === null || $x->foo())
            [$right_context->vars_in_scope, $right_context->references_in_scope] = Reconciler::reconcile_keyed_types($negated_type_assertions, $active_negated_type_assertions, $right_context->vars_in_scope, $right_context->references_in_scope, $changed_var_ids, $left_referenced_var_ids, $statements_analyzer, [], $left_context->inside_loop, new Code_Location($statements_analyzer->get_source(), $stmt->left), !$context->inside_negation);
        }
        $right_context->clauses = $clauses_for_right_analysis;
        if ($changed_var_ids) {
            $partitioned_clauses = Context::remove_reconciled_clauses($right_context->clauses, $changed_var_ids);
            $right_context->clauses = $partitioned_clauses[0];
            $right_context->reconciled_expression_clauses = $context->reconciled_expression_clauses;
            foreach ($partitioned_clauses[1] as $clause) {
                $right_context->reconciled_expression_clauses[] = $clause->hash;
            }
            $partitioned_clauses = Context::remove_reconciled_clauses($context->clauses, $changed_var_ids);
            $context->clauses = $partitioned_clauses[0];
            foreach ($partitioned_clauses[1] as $clause) {
                $context->reconciled_expression_clauses[] = $clause->hash;
            }
        }
        $right_context->if_body_context = null;
        $pre_referenced_var_ids = $right_context->cond_referenced_var_ids;
        $right_context->cond_referenced_var_ids = [];
        $pre_assigned_var_ids = $right_context->assigned_var_ids;
        $right_context->assigned_var_ids = [];
        if (Expression_Analyzer::analyze($statements_analyzer, $stmt->right, $right_context) === false) {
            return false;
        }
        If_Conditional_Analyzer::handle_paradoxical_condition($statements_analyzer, $stmt->right);
        $right_referenced_var_ids = $right_context->cond_referenced_var_ids;
        $right_context->cond_referenced_var_ids = array_merge($pre_referenced_var_ids, $right_referenced_var_ids);
        $right_assigned_var_ids = $right_context->assigned_var_ids;
        $right_context->assigned_var_ids = array_merge($pre_assigned_var_ids, $right_assigned_var_ids);
        $right_cond_id = spl_object_id($stmt->right);
        $right_clauses = Formula_Generator::get_formula($right_cond_id, $right_cond_id, $stmt->right, $context->self, $statements_analyzer, $codebase);
        $clauses_for_right_analysis = Context::remove_reconciled_clauses($clauses_for_right_analysis, $right_assigned_var_ids)[0];
        $combined_right_clauses = Algebra::simplify_cnf([...$clauses_for_right_analysis, ...$right_clauses]);
        $active_right_type_assertions = [];
        $right_type_assertions = Algebra::get_truths_from_formula($combined_right_clauses, $right_cond_id, $right_referenced_var_ids, $active_right_type_assertions);
        if ($right_type_assertions) {
            $right_changed_var_ids = [];
            Reconciler::reconcile_keyed_types($right_type_assertions, $active_right_type_assertions, $right_context->vars_in_scope, $right_context->references_in_scope, $right_changed_var_ids, $right_referenced_var_ids, $statements_analyzer, [], $left_context->inside_loop, new Code_Location($statements_analyzer->get_source(), $stmt->right), $context->inside_negation);
        }
        if (!$stmt->right instanceof Php_Parser\Node\Expr\Exit_) {
            foreach ($right_context->vars_in_scope as $var_id => $type) {
                if (isset($context->vars_in_scope[$var_id])) {
                    $context->vars_in_scope[$var_id] = Type::combine_union_types($context->vars_in_scope[$var_id], $type, $codebase);
                }
            }
        } elseif ($stmt->left instanceof Php_Parser\Node\Expr\Assign) {
            $var_id = Expression_Identifier::get_var_id($stmt->left->var, $context->self);
            if ($var_id && isset($left_context->vars_in_scope[$var_id])) {
                $left_inferred_reconciled = Assertion_Reconciler::reconcile(new Truthy(), $left_context->vars_in_scope[$var_id], '', $statements_analyzer, $context->inside_loop, [], new Code_Location($statements_analyzer->get_source(), $stmt->left), $statements_analyzer->get_suppressed_issues());
                $context->vars_in_scope[$var_id] = $left_inferred_reconciled;
            }
        }
        if ($context->inside_conditional) {
            $context->update_checks($right_context);
        }
        $context->cond_referenced_var_ids = [...$right_context->cond_referenced_var_ids, ...$context->cond_referenced_var_ids];
        $context->assigned_var_ids = [...$context->assigned_var_ids, ...$right_context->assigned_var_ids];
        if ($context->if_body_context) {
            $if_body_context = $context->if_body_context;
            foreach ($right_context->vars_in_scope as $var_id => $type) {
                if (isset($if_body_context->vars_in_scope[$var_id])) {
                    $if_body_context->vars_in_scope[$var_id] = Type::combine_union_types($type, $if_body_context->vars_in_scope[$var_id], $codebase);
                } elseif (isset($left_context->vars_in_scope[$var_id])) {
                    $if_body_context->vars_in_scope[$var_id] = Type::combine_union_types($type, $left_context->vars_in_scope[$var_id], $codebase);
                }
            }
            $if_body_context->cond_referenced_var_ids = [...$context->cond_referenced_var_ids, ...$if_body_context->cond_referenced_var_ids];
            $if_body_context->assigned_var_ids = [...$context->assigned_var_ids, ...$if_body_context->assigned_var_ids];
            $if_body_context->update_checks($context);
        }
        $context->vars_possibly_in_scope = [...$right_context->vars_possibly_in_scope, ...$context->vars_possibly_in_scope];
        return true;
    }
}