<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Block\If_Else;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Internal\Algebra;
use Psalm\Internal\Analyzer\Scope_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Scope\If_Scope;
use Psalm\Internal\Type\Comparator\Union_Type_Comparator;
use Psalm\Issue\Conflicting_Reference_Constraint;
use Psalm\Issue_Buffer;
use Psalm\Type\Reconciler;
use function array_diff_key;
use function array_key_exists;
use function array_keys;
use function array_merge;
use function count;
use function in_array;
use function preg_match;
use function preg_quote;
/**
 * @internal
 */
final class Else_Analyzer
{
    /**
     * @return false|null
     */
    public static function analyze(Statements_Analyzer $statements_analyzer, ?Php_Parser\Node\Stmt\Else_ $else, If_Scope $if_scope, Context $else_context, Context $outer_context): ?bool
    {
        $codebase = $statements_analyzer->get_codebase();
        if (!$else && !$if_scope->negated_clauses && !$else_context->clauses) {
            $if_scope->final_actions = array_merge([Scope_Analyzer::ACTION_NONE], $if_scope->final_actions);
            $if_scope->assigned_var_ids = [];
            $if_scope->new_vars = [];
            $if_scope->redefined_vars = [];
            $if_scope->reasonable_clauses = [];
            return null;
        }
        $else_context->clauses = Algebra::simplify_cnf([...$else_context->clauses, ...$if_scope->negated_clauses]);
        $else_types = Algebra::get_truths_from_formula($else_context->clauses);
        $original_context = clone $else_context;
        if ($else_types) {
            $changed_var_ids = [];
            [$else_context->vars_in_scope, $else_context->references_in_scope] = Reconciler::reconcile_keyed_types($else_types, [], $else_context->vars_in_scope, $else_context->references_in_scope, $changed_var_ids, [], $statements_analyzer, $statements_analyzer->get_template_type_map() ?: [], $else_context->inside_loop, $else ? new Code_Location($statements_analyzer->get_source(), $else, $outer_context->include_location) : null);
            $else_context->clauses = Context::remove_reconciled_clauses($else_context->clauses, $changed_var_ids)[0];
            foreach ($changed_var_ids as $changed_var_id => $_) {
                foreach ($else_context->vars_in_scope as $var_id => $_) {
                    if (preg_match('/' . preg_quote($changed_var_id, '/') . '[\]\[\-]/', $var_id) && !array_key_exists($var_id, $changed_var_ids)) {
                        $else_context->remove_possible_reference($var_id);
                    }
                }
            }
        }
        $old_else_context = clone $else_context;
        $pre_stmts_assigned_var_ids = $else_context->assigned_var_ids;
        $else_context->assigned_var_ids = [];
        $pre_possibly_assigned_var_ids = $else_context->possibly_assigned_var_ids;
        $else_context->possibly_assigned_var_ids = [];
        if ($else) {
            if ($statements_analyzer->analyze($else->stmts, $else_context) === false) {
                return false;
            }
        }
        foreach ($else_context->parent_remove_vars as $var_id => $_) {
            $outer_context->remove_var_from_conflicting_clauses($var_id);
        }
        /** @var array<string, int> */
        $new_assigned_var_ids = $else_context->assigned_var_ids;
        $else_context->assigned_var_ids += $pre_stmts_assigned_var_ids;
        /** @var array<string, bool> */
        $new_possibly_assigned_var_ids = $else_context->possibly_assigned_var_ids;
        $else_context->possibly_assigned_var_ids += $pre_possibly_assigned_var_ids;
        if ($else) {
            foreach ($else_context->byref_constraints as $var_id => $byref_constraint) {
                if (isset($outer_context->byref_constraints[$var_id]) && ($outer_constraint_type = $outer_context->byref_constraints[$var_id]->type) && $byref_constraint->type && !Union_Type_Comparator::is_contained_by($codebase, $byref_constraint->type, $outer_constraint_type)) {
                    Issue_Buffer::maybe_add(new Conflicting_Reference_Constraint('There is more than one pass-by-reference constraint on ' . $var_id, new Code_Location($statements_analyzer, $else, $outer_context->include_location, true)), $statements_analyzer->get_suppressed_issues());
                } else {
                    $outer_context->byref_constraints[$var_id] = $byref_constraint;
                }
            }
        }
        $final_actions = $else ? Scope_Analyzer::get_control_actions($else->stmts, $statements_analyzer->node_data, []) : [Scope_Analyzer::ACTION_NONE];
        // has a return/throw at end
        $has_ending_statements = $final_actions === [Scope_Analyzer::ACTION_END];
        $has_leaving_statements = $has_ending_statements || count($final_actions) && !in_array(Scope_Analyzer::ACTION_NONE, $final_actions, true);
        $has_break_statement = $final_actions === [Scope_Analyzer::ACTION_BREAK];
        $has_continue_statement = $final_actions === [Scope_Analyzer::ACTION_CONTINUE];
        $if_scope->final_actions = array_merge($final_actions, $if_scope->final_actions);
        // if it doesn't end in a return
        if (!$has_leaving_statements) {
            If_Analyzer::update_if_scope($codebase, $if_scope, $else_context, $original_context, $new_assigned_var_ids, $new_possibly_assigned_var_ids, $if_scope->if_cond_changed_var_ids, true);
            $if_scope->reasonable_clauses = [];
        }
        // update the parent context as necessary
        if ($if_scope->negatable_if_types) {
            $outer_context->update($old_else_context, $else_context, $has_leaving_statements, array_keys($if_scope->negatable_if_types), $if_scope->updated_vars);
        }
        if (!$has_ending_statements) {
            $vars_possibly_in_scope = array_diff_key($else_context->vars_possibly_in_scope, $outer_context->vars_possibly_in_scope);
            $possibly_assigned_var_ids = $new_possibly_assigned_var_ids;
            if ($has_leaving_statements) {
                if ($else_context->loop_scope) {
                    if (!$has_continue_statement && !$has_break_statement) {
                        $if_scope->new_vars_possibly_in_scope = [...$vars_possibly_in_scope, ...$if_scope->new_vars_possibly_in_scope];
                    }
                    $else_context->loop_scope->vars_possibly_in_scope = [...$vars_possibly_in_scope, ...$else_context->loop_scope->vars_possibly_in_scope];
                }
            } else {
                $if_scope->new_vars_possibly_in_scope = [...$vars_possibly_in_scope, ...$if_scope->new_vars_possibly_in_scope];
                $if_scope->possibly_assigned_var_ids = array_merge($possibly_assigned_var_ids, $if_scope->possibly_assigned_var_ids);
            }
        }
        if ($outer_context->collect_exceptions) {
            $outer_context->merge_exceptions($else_context);
        }
        // Track references set in the else to make sure they aren't reused later
        $outer_context->update_references_possibly_from_confusing_scope($else_context, $statements_analyzer);
        return null;
    }
}