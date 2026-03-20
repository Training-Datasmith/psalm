<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Block\If_Else;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Internal\Algebra;
use Psalm\Internal\Analyzer\Scope_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Clause;
use Psalm\Internal\Scope\If_Conditional_Scope;
use Psalm\Internal\Scope\If_Scope;
use Psalm\Internal\Type\Comparator\Union_Type_Comparator;
use Psalm\Issue\Conflicting_Reference_Constraint;
use Psalm\Issue_Buffer;
use Psalm\Node\Expr\Binary_Op\Virtual_Boolean_Or;
use Psalm\Node\Expr\Virtual_Boolean_Not;
use Psalm\Node\Expr\Virtual_Func_Call;
use Psalm\Node\Name\Virtual_Fully_Qualified;
use Psalm\Node\Virtual_Arg;
use Psalm\Type;
use Psalm\Type\Reconciler;
use Psalm\Type\Union;
use function array_any;
use function array_combine;
use function array_diff_key;
use function array_filter;
use function array_intersect;
use function array_intersect_key;
use function array_key_exists;
use function array_keys;
use function array_merge;
use function array_reduce;
use function count;
use function in_array;
use function preg_match;
use function preg_quote;
use function spl_object_id;
/**
 * @internal
 */
final class If_Analyzer
{
    /**
     * @param  array<string, Union> $pre_assignment_else_redefined_vars
     * @return false|null
     */
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Stmt\If_ $stmt, If_Scope $if_scope, If_Conditional_Scope $if_conditional_scope, Context $if_context, Context $outer_context, array $pre_assignment_else_redefined_vars): ?bool
    {
        $cond_referenced_var_ids = $if_conditional_scope->cond_referenced_var_ids;
        $active_if_types = [];
        $reconcilable_if_types = Algebra::get_truths_from_formula($if_context->clauses, spl_object_id($stmt->cond), $cond_referenced_var_ids, $active_if_types);
        if (array_any($outer_context->clauses, static fn(Clause $clause): bool => (bool) $clause->possibilities)) {
            $omit_keys = array_reduce(
                $outer_context->clauses,
                /**
                 * @param array<string> $carry
                 * @return array<string>
                 */
                static fn(array $carry, Clause $clause): array => array_merge($carry, array_keys($clause->possibilities)),
                []
            );
            $omit_keys = array_combine($omit_keys, $omit_keys);
            $omit_keys = array_diff_key($omit_keys, Algebra::get_truths_from_formula($outer_context->clauses));
            $cond_referenced_var_ids = array_diff_key($cond_referenced_var_ids, $omit_keys);
        }
        // if the if has an || in the conditional, we cannot easily reason about it
        if ($reconcilable_if_types) {
            $changed_var_ids = [];
            [$if_context->vars_in_scope, $if_context->references_in_scope] = Reconciler::reconcile_keyed_types($reconcilable_if_types, $active_if_types, $if_context->vars_in_scope, $if_context->references_in_scope, $changed_var_ids, $cond_referenced_var_ids, $statements_analyzer, $statements_analyzer->get_template_type_map() ?: [], $if_context->inside_loop, $outer_context->check_variables ? new Code_Location($statements_analyzer->get_source(), $stmt->cond instanceof Php_Parser\Node\Expr\Boolean_Not ? $stmt->cond->expr : $stmt->cond, $outer_context->include_location) : null);
            foreach ($reconcilable_if_types as $var_id => $_) {
                $if_context->vars_possibly_in_scope[$var_id] = true;
            }
            if ($changed_var_ids) {
                $if_context->clauses = Context::remove_reconciled_clauses($if_context->clauses, $changed_var_ids)[0];
                foreach ($changed_var_ids as $changed_var_id => $_) {
                    foreach ($if_context->vars_in_scope as $var_id => $_) {
                        if (preg_match('/' . preg_quote($changed_var_id, '/') . '[\]\[\-]/', $var_id) && !array_key_exists($var_id, $changed_var_ids) && !array_key_exists($var_id, $cond_referenced_var_ids)) {
                            $if_context->remove_possible_reference($var_id);
                        }
                    }
                }
            }
            $if_scope->if_cond_changed_var_ids = $changed_var_ids;
        }
        $if_context->reconciled_expression_clauses = [];
        $outer_context->vars_possibly_in_scope = [...$if_context->vars_possibly_in_scope, ...$outer_context->vars_possibly_in_scope];
        $old_if_context = clone $if_context;
        $codebase = $statements_analyzer->get_codebase();
        $assigned_var_ids = $if_context->assigned_var_ids;
        $possibly_assigned_var_ids = $if_context->possibly_assigned_var_ids;
        $if_context->assigned_var_ids = [];
        $if_context->possibly_assigned_var_ids = [];
        if ($statements_analyzer->analyze($stmt->stmts, $if_context) === false) {
            return false;
        }
        foreach ($if_context->parent_remove_vars as $var_id => $_) {
            $outer_context->remove_var_from_conflicting_clauses($var_id);
        }
        $final_actions = Scope_Analyzer::get_control_actions($stmt->stmts, $statements_analyzer->node_data, []);
        // has a return/throw at end
        $has_ending_statements = $final_actions === [Scope_Analyzer::ACTION_END];
        $has_leaving_statements = $has_ending_statements || count($final_actions) && !in_array(Scope_Analyzer::ACTION_NONE, $final_actions, true);
        $has_break_statement = $final_actions === [Scope_Analyzer::ACTION_BREAK];
        $has_continue_statement = $final_actions === [Scope_Analyzer::ACTION_CONTINUE];
        $if_scope->if_actions = $final_actions;
        $if_scope->final_actions = $final_actions;
        /** @var array<string, int> */
        $new_assigned_var_ids = $if_context->assigned_var_ids;
        /** @var array<string, bool> */
        $new_possibly_assigned_var_ids = $if_context->possibly_assigned_var_ids;
        $if_context->assigned_var_ids = array_merge($assigned_var_ids, $new_assigned_var_ids);
        $if_context->possibly_assigned_var_ids = array_merge($possibly_assigned_var_ids, $new_possibly_assigned_var_ids);
        foreach ($if_context->byref_constraints as $var_id => $byref_constraint) {
            if (isset($outer_context->byref_constraints[$var_id]) && $byref_constraint->type && ($outer_constraint_type = $outer_context->byref_constraints[$var_id]->type) && !Union_Type_Comparator::is_contained_by($codebase, $byref_constraint->type, $outer_constraint_type)) {
                Issue_Buffer::maybe_add(new Conflicting_Reference_Constraint('There is more than one pass-by-reference constraint on ' . $var_id . ' between ' . $byref_constraint->type->get_id() . ' and ' . $outer_constraint_type->get_id(), new Code_Location($statements_analyzer, $stmt, $outer_context->include_location, true)), $statements_analyzer->get_suppressed_issues());
            } else {
                $outer_context->byref_constraints[$var_id] = $byref_constraint;
            }
        }
        if (!$has_leaving_statements) {
            self::update_if_scope($codebase, $if_scope, $if_context, $outer_context, $new_assigned_var_ids, $new_possibly_assigned_var_ids, $if_scope->if_cond_changed_var_ids);
            if ($if_scope->reasonable_clauses) {
                // remove all reasonable clauses that would be negated by the if stmts
                foreach ($new_assigned_var_ids as $var_id => $_) {
                    $if_scope->reasonable_clauses = Context::filter_clauses($var_id, $if_scope->reasonable_clauses, $if_context->vars_in_scope[$var_id] ?? null, $statements_analyzer);
                }
            }
        } else if (!$has_break_statement) {
            $if_scope->reasonable_clauses = [];
            // If we're assigning inside
            if ($if_conditional_scope->assigned_in_conditional_var_ids && $if_scope->post_leaving_if_context) {
                self::add_conditionally_assigned_vars_to_context($statements_analyzer, $stmt->cond, $if_scope->post_leaving_if_context, $outer_context, $if_conditional_scope->assigned_in_conditional_var_ids);
            }
        }
        // update the parent context as necessary, but only if we can safely reason about type negation.
        // We only update vars that changed both at the start of the if block and then again by an assignment
        // in the if statement.
        if ($if_scope->negated_types) {
            $vars_to_update = array_intersect(array_keys($pre_assignment_else_redefined_vars), array_keys($if_scope->negated_types));
            $outer_context->update($old_if_context, $if_context, $has_leaving_statements, $vars_to_update, $if_scope->updated_vars);
        }
        if (!$has_ending_statements) {
            $vars_possibly_in_scope = array_diff_key($if_context->vars_possibly_in_scope, $outer_context->vars_possibly_in_scope);
            if ($if_context->loop_scope) {
                if (!$has_continue_statement && !$has_break_statement) {
                    $if_scope->new_vars_possibly_in_scope = $vars_possibly_in_scope;
                }
                $if_context->loop_scope->vars_possibly_in_scope = [...$vars_possibly_in_scope, ...$if_context->loop_scope->vars_possibly_in_scope];
            } elseif (!$has_leaving_statements) {
                $if_scope->new_vars_possibly_in_scope = $vars_possibly_in_scope;
            }
        }
        if ($outer_context->collect_exceptions) {
            $outer_context->merge_exceptions($if_context);
        }
        // Track references set in the if to make sure they aren't reused later
        $outer_context->update_references_possibly_from_confusing_scope($if_context, $statements_analyzer);
        return null;
    }
    /**
     * @param array<string, int> $assigned_in_conditional_var_ids
     */
    public static function add_conditionally_assigned_vars_to_context(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr $cond, Context $post_leaving_if_context, Context $post_if_context, array $assigned_in_conditional_var_ids): void
    {
        // this filters out coercions to expected types in ArgumentAnalyzer
        $assigned_in_conditional_var_ids = array_filter($assigned_in_conditional_var_ids);
        if (!$assigned_in_conditional_var_ids) {
            return;
        }
        $exprs = self::get_definitely_evaluated_ored_expressions($cond);
        // if there was no assignment in the first expression it's safe to proceed
        $old_node_data = $statements_analyzer->node_data;
        $statements_analyzer->node_data = clone $old_node_data;
        Issue_Buffer::start_recording();
        foreach ($exprs as $expr) {
            if ($expr instanceof Php_Parser\Node\Expr\Binary_Op\Boolean_And) {
                $fake_not = new Virtual_Boolean_Or(self::negate_expr($expr->left), self::negate_expr($expr->right), $expr->get_attributes());
            } else {
                $fake_not = self::negate_expr($expr);
            }
            $fake_negated_expr = new Virtual_Func_Call(new Virtual_Fully_Qualified('assert'), [new Virtual_Arg($fake_not, false, false, $expr->get_attributes())], $expr->get_attributes());
            $post_leaving_if_context->inside_negation = !$post_leaving_if_context->inside_negation;
            Expression_Analyzer::analyze($statements_analyzer, $fake_negated_expr, $post_leaving_if_context);
            $post_leaving_if_context->inside_negation = !$post_leaving_if_context->inside_negation;
        }
        Issue_Buffer::clear_recording_level();
        Issue_Buffer::stop_recording();
        $statements_analyzer->node_data = $old_node_data;
        foreach ($assigned_in_conditional_var_ids as $var_id => $_) {
            if (isset($post_leaving_if_context->vars_in_scope[$var_id])) {
                $post_if_context->vars_in_scope[$var_id] = $post_leaving_if_context->vars_in_scope[$var_id];
            }
        }
    }
    /**
     * Returns all expressions inside an ored expression
     *
     * @return non-empty-list<PhpParser\Node\Expr>
     */
    private static function get_definitely_evaluated_ored_expressions(Php_Parser\Node\Expr $stmt): array
    {
        if ($stmt instanceof Php_Parser\Node\Expr\Binary_Op\Boolean_Or || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Logical_Or || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Logical_Xor) {
            return [...self::get_definitely_evaluated_ored_expressions($stmt->left), ...self::get_definitely_evaluated_ored_expressions($stmt->right)];
        }
        return [$stmt];
    }
    private static function negate_expr(Php_Parser\Node\Expr $expr): Php_Parser\Node\Expr
    {
        if ($expr instanceof Php_Parser\Node\Expr\Boolean_Not) {
            return $expr->expr;
        }
        return new Virtual_Boolean_Not($expr, $expr->get_attributes());
    }
    /**
     * @param  array<string, int>    $assigned_var_ids
     * @param  array<string, bool>   $possibly_assigned_var_ids
     * @param  array<string, bool>   $newly_reconciled_var_ids
     */
    public static function update_if_scope(Codebase $codebase, If_Scope $if_scope, Context $if_context, Context $outer_context, array $assigned_var_ids, array $possibly_assigned_var_ids, array $newly_reconciled_var_ids, bool $update_new_vars = true): void
    {
        $redefined_vars = $if_context->get_redefined_vars($outer_context->vars_in_scope);
        if ($if_scope->new_vars === null) {
            if ($update_new_vars) {
                $if_scope->new_vars = array_diff_key($if_context->vars_in_scope, $outer_context->vars_in_scope);
            }
        } else {
            foreach ($if_scope->new_vars as $new_var => $type) {
                if (!$if_context->has_variable($new_var)) {
                    unset($if_scope->new_vars[$new_var]);
                } else {
                    $if_scope->new_vars[$new_var] = Type::combine_union_types($type, $if_context->vars_in_scope[$new_var], $codebase);
                }
            }
        }
        $possibly_redefined_vars = $redefined_vars;
        foreach ($possibly_redefined_vars as $var_id => $_) {
            if (!isset($possibly_assigned_var_ids[$var_id]) && isset($newly_reconciled_var_ids[$var_id])) {
                unset($possibly_redefined_vars[$var_id]);
            }
        }
        if ($if_scope->assigned_var_ids === null) {
            $if_scope->assigned_var_ids = $assigned_var_ids;
        } else {
            $if_scope->assigned_var_ids = array_intersect_key($assigned_var_ids, $if_scope->assigned_var_ids);
        }
        $if_scope->possibly_assigned_var_ids += $possibly_assigned_var_ids;
        if ($if_scope->redefined_vars === null) {
            $if_scope->redefined_vars = $redefined_vars;
            $if_scope->possibly_redefined_vars = $possibly_redefined_vars;
        } else {
            foreach ($if_scope->redefined_vars as $redefined_var => $type) {
                if (!isset($redefined_vars[$redefined_var])) {
                    unset($if_scope->redefined_vars[$redefined_var]);
                } else {
                    $if_scope->redefined_vars[$redefined_var] = Type::combine_union_types($redefined_vars[$redefined_var], $type, $codebase);
                    if (isset($outer_context->vars_in_scope[$redefined_var]) && $if_scope->redefined_vars[$redefined_var]->equals($outer_context->vars_in_scope[$redefined_var])) {
                        unset($if_scope->redefined_vars[$redefined_var]);
                    }
                }
            }
            foreach ($possibly_redefined_vars as $var => $type) {
                $if_scope->possibly_redefined_vars[$var] = Type::combine_union_types($type, $if_scope->possibly_redefined_vars[$var] ?? null, $codebase);
            }
        }
    }
}