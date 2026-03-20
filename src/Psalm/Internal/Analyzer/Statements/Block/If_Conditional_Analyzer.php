<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Block;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Exception\Scope_Analysis_Exception;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Clause;
use Psalm\Internal\Scope\If_Conditional_Scope;
use Psalm\Internal\Scope\If_Scope;
use Psalm\Issue\Docblock_Type_Contradiction;
use Psalm\Issue\Redundant_Condition;
use Psalm\Issue\Redundant_Condition_Given_Docblock_Type;
use Psalm\Issue\Type_Does_Not_Contain_Type;
use Psalm\Issue_Buffer;
use Psalm\Type\Reconciler;
use function array_diff_key;
use function array_filter;
use function array_key_first;
use function array_merge;
use function array_values;
use function count;
/**
 * @internal
 */
final class If_Conditional_Analyzer
{
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr $cond, Context $outer_context, Codebase $codebase, If_Scope $if_scope, int $branch_point): If_Conditional_Scope
    {
        $entry_clauses = [];
        // used when evaluating elseifs
        if ($if_scope->negated_clauses) {
            $entry_clauses = [...$outer_context->clauses, ...$if_scope->negated_clauses];
            $changed_var_ids = [];
            if ($if_scope->negated_types) {
                [$vars_reconciled, $references_reconciled] = Reconciler::reconcile_keyed_types($if_scope->negated_types, [], $outer_context->vars_in_scope, $outer_context->references_in_scope, $changed_var_ids, [], $statements_analyzer, [], $outer_context->inside_loop, new Code_Location($statements_analyzer->get_source(), $cond instanceof Php_Parser\Node\Expr\Boolean_Not ? $cond->expr : $cond, $outer_context->include_location, false));
                if ($changed_var_ids) {
                    $outer_context = clone $outer_context;
                    $outer_context->vars_in_scope = $vars_reconciled;
                    $outer_context->references_in_scope = $references_reconciled;
                    $entry_clauses = array_values(array_filter($entry_clauses, static fn(Clause $c): bool => count($c->possibilities) > 1 || $c->wedge || count($c->possibilities) > 0 && !isset($changed_var_ids[array_key_first($c->possibilities)])));
                }
            }
        }
        // get the first expression in the if, which should be evaluated on its own
        // this allows us to update the context of $matches in
        // if (!preg_match('/a/', 'aa', $matches)) {
        //   exit
        // }
        // echo $matches[0];
        $externally_applied_if_cond_expr = self::get_definitely_evaluated_expression_after_if($cond);
        $internally_applied_if_cond_expr = self::get_definitely_evaluated_expression_inside_if($cond);
        $pre_condition_vars_in_scope = $outer_context->vars_in_scope;
        $referenced_var_ids = $outer_context->cond_referenced_var_ids;
        $outer_context->cond_referenced_var_ids = [];
        $pre_assigned_var_ids = $outer_context->assigned_var_ids;
        $outer_context->assigned_var_ids = [];
        $if_context = null;
        if ($internally_applied_if_cond_expr !== $externally_applied_if_cond_expr) {
            $if_context = clone $outer_context;
        }
        $was_inside_conditional = $outer_context->inside_conditional;
        $outer_context->inside_conditional = true;
        if (Expression_Analyzer::analyze($statements_analyzer, $externally_applied_if_cond_expr, $outer_context) === false) {
            throw new Scope_Analysis_Exception();
        }
        $first_cond_assigned_var_ids = $outer_context->assigned_var_ids;
        $outer_context->assigned_var_ids = array_merge($pre_assigned_var_ids, $first_cond_assigned_var_ids);
        $first_cond_referenced_var_ids = $outer_context->cond_referenced_var_ids;
        $outer_context->cond_referenced_var_ids = array_merge($referenced_var_ids, $first_cond_referenced_var_ids);
        $outer_context->inside_conditional = $was_inside_conditional;
        if (!$if_context) {
            $if_context = clone $outer_context;
        }
        $if_conditional_context = clone $if_context;
        // here we set up a context specifically for the statements in the first `if`, which can
        // be affected by statements in the if condition
        $if_conditional_context->if_body_context = $if_context;
        if ($codebase->alter_code) {
            $if_context->branch_point = $branch_point;
        }
        // we need to clone the current context so our ongoing updates
        // to $outer_context don't mess with elseif/else blocks
        $post_if_context = clone $outer_context;
        if ($internally_applied_if_cond_expr !== $cond || $externally_applied_if_cond_expr !== $cond) {
            $assigned_var_ids = $first_cond_assigned_var_ids;
            $if_conditional_context->assigned_var_ids = [];
            $referenced_var_ids = $first_cond_referenced_var_ids;
            $if_conditional_context->cond_referenced_var_ids = [];
            $was_inside_conditional = $if_conditional_context->inside_conditional;
            $if_conditional_context->inside_conditional = true;
            if (Expression_Analyzer::analyze($statements_analyzer, $cond, $if_conditional_context) === false) {
                throw new Scope_Analysis_Exception();
            }
            $if_conditional_context->inside_conditional = $was_inside_conditional;
            /** @var array<string, bool> */
            $more_cond_referenced_var_ids = $if_conditional_context->cond_referenced_var_ids;
            $if_conditional_context->cond_referenced_var_ids = array_merge($more_cond_referenced_var_ids, $referenced_var_ids);
            $cond_referenced_var_ids = array_merge($first_cond_referenced_var_ids, $more_cond_referenced_var_ids);
            /** @var array<string, int> */
            $more_cond_assigned_var_ids = $if_conditional_context->assigned_var_ids;
            $if_conditional_context->assigned_var_ids = array_merge($more_cond_assigned_var_ids, $assigned_var_ids);
            $assigned_in_conditional_var_ids = array_merge($first_cond_assigned_var_ids, $more_cond_assigned_var_ids);
        } else {
            $cond_referenced_var_ids = $first_cond_referenced_var_ids;
            $assigned_in_conditional_var_ids = $first_cond_assigned_var_ids;
        }
        $newish_var_ids = [];
        foreach (array_diff_key($if_conditional_context->vars_in_scope, $pre_condition_vars_in_scope, $cond_referenced_var_ids, $assigned_in_conditional_var_ids) as $name => $_value) {
            $newish_var_ids[$name] = true;
        }
        self::handle_paradoxical_condition($statements_analyzer, $cond, true);
        // get all the var ids that were referenced in the conditional, but not assigned in it
        $cond_referenced_var_ids = array_diff_key($cond_referenced_var_ids, $assigned_in_conditional_var_ids);
        $cond_referenced_var_ids = [...$newish_var_ids, ...$cond_referenced_var_ids];
        return new If_Conditional_Scope($if_context, $post_if_context, $cond_referenced_var_ids, $assigned_in_conditional_var_ids, $entry_clauses);
    }
    /**
     * Returns statements that are definitely evaluated before any statements after the end of the
     * if/elseif/else blocks
     */
    private static function get_definitely_evaluated_expression_after_if(Php_Parser\Node\Expr $stmt): Php_Parser\Node\Expr
    {
        if ($stmt instanceof Php_Parser\Node\Expr\Binary_Op\Equal || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Identical) {
            if ($stmt->left instanceof Php_Parser\Node\Expr\Const_Fetch && $stmt->left->name->get_parts() === ['true']) {
                return self::get_definitely_evaluated_expression_after_if($stmt->right);
            }
            if ($stmt->right instanceof Php_Parser\Node\Expr\Const_Fetch && $stmt->right->name->get_parts() === ['true']) {
                return self::get_definitely_evaluated_expression_after_if($stmt->left);
            }
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Binary_Op) {
            if ($stmt instanceof Php_Parser\Node\Expr\Binary_Op\Boolean_And || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Logical_And || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Logical_Xor) {
                return self::get_definitely_evaluated_expression_after_if($stmt->left);
            }
            return $stmt;
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Boolean_Not) {
            $inner_stmt = self::get_definitely_evaluated_expression_inside_if($stmt->expr);
            if ($inner_stmt !== $stmt->expr) {
                return $inner_stmt;
            }
        }
        return $stmt;
    }
    /**
     * Returns statements that are definitely evaluated before any statements inside
     * the if block
     */
    private static function get_definitely_evaluated_expression_inside_if(Php_Parser\Node\Expr $stmt): Php_Parser\Node\Expr
    {
        if ($stmt instanceof Php_Parser\Node\Expr\Binary_Op\Equal || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Identical) {
            if ($stmt->left instanceof Php_Parser\Node\Expr\Const_Fetch && $stmt->left->name->get_parts() === ['true']) {
                return self::get_definitely_evaluated_expression_inside_if($stmt->right);
            }
            if ($stmt->right instanceof Php_Parser\Node\Expr\Const_Fetch && $stmt->right->name->get_parts() === ['true']) {
                return self::get_definitely_evaluated_expression_inside_if($stmt->left);
            }
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Binary_Op) {
            if ($stmt instanceof Php_Parser\Node\Expr\Binary_Op\Boolean_Or || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Logical_Or || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Logical_Xor) {
                return self::get_definitely_evaluated_expression_inside_if($stmt->left);
            }
            return $stmt;
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Boolean_Not) {
            $inner_stmt = self::get_definitely_evaluated_expression_after_if($stmt->expr);
            if ($inner_stmt !== $stmt->expr) {
                return $inner_stmt;
            }
        }
        return $stmt;
    }
    public static function handle_paradoxical_condition(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr $stmt, bool $emit_redundant_with_assignation = false): void
    {
        $type = $statements_analyzer->node_data->get_type($stmt);
        if ($type !== null) {
            if ($type->is_always_falsy()) {
                if ($type->from_docblock) {
                    Issue_Buffer::maybe_add(new Docblock_Type_Contradiction('Operand of type ' . $type->get_id() . ' is always falsy', new Code_Location($statements_analyzer, $stmt), $type->get_id() . ' falsy'), $statements_analyzer->get_suppressed_issues());
                } else {
                    Issue_Buffer::maybe_add(new Type_Does_Not_Contain_Type('Operand of type ' . $type->get_id() . ' is always falsy', new Code_Location($statements_analyzer, $stmt), $type->get_id() . ' falsy'), $statements_analyzer->get_suppressed_issues());
                }
            } elseif ($type->is_always_truthy() && (!$stmt instanceof Php_Parser\Node\Expr\Assign || $emit_redundant_with_assignation)) {
                if ($type->from_docblock) {
                    Issue_Buffer::maybe_add(new Redundant_Condition_Given_Docblock_Type('Operand of type ' . $type->get_id() . ' is always truthy', new Code_Location($statements_analyzer, $stmt), $type->get_id() . ' falsy'), $statements_analyzer->get_suppressed_issues());
                } else {
                    Issue_Buffer::maybe_add(new Redundant_Condition('Operand of type ' . $type->get_id() . ' is always truthy', new Code_Location($statements_analyzer, $stmt), $type->get_id() . ' falsy'), $statements_analyzer->get_suppressed_issues());
                }
            } elseif (!$stmt instanceof Php_Parser\Node\Expr\Binary_Op\Not_Identical && !$stmt instanceof Php_Parser\Node\Expr\Binary_Op\Identical && !$stmt instanceof Php_Parser\Node\Expr\Boolean_Not) {
                Expression_Analyzer::check_risky_truthy_falsy_comparison($type, $statements_analyzer, $stmt);
            }
        }
    }
}