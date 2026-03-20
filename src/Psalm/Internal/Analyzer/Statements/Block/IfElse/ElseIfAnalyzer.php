<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Block\If_Else;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Exception\Complicated_Expression_Exception;
use Psalm\Exception\Scope_Analysis_Exception;
use Psalm\Internal\Algebra;
use Psalm\Internal\Algebra\Formula_Generator;
use Psalm\Internal\Analyzer\Algebra_Analyzer;
use Psalm\Internal\Analyzer\Scope_Analyzer;
use Psalm\Internal\Analyzer\Statements\Block\If_Conditional_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Clause;
use Psalm\Internal\Scope\If_Scope;
use Psalm\Internal\Type\Comparator\Union_Type_Comparator;
use Psalm\Issue\Conflicting_Reference_Constraint;
use Psalm\Issue_Buffer;
use Psalm\Type\Reconciler;
use function array_any;
use function array_combine;
use function array_diff;
use function array_diff_key;
use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_merge;
use function array_reduce;
use function array_unique;
use function array_values;
use function count;
use function in_array;
use function preg_match;
use function preg_quote;
use function spl_object_id;
/**
 * @internal
 */
final class Else_If_Analyzer
{
    /**
     * @return false|null
     */
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Stmt\Else_If_ $elseif, If_Scope $if_scope, Context $else_context, Context $outer_context, Codebase $codebase, int $branch_point): ?bool
    {
        $pre_conditional_context = clone $else_context;
        try {
            $if_conditional_scope = If_Conditional_Analyzer::analyze($statements_analyzer, $elseif->cond, $else_context, $codebase, $if_scope, $branch_point);
            $elseif_context = $if_conditional_scope->if_context;
            $cond_referenced_var_ids = $if_conditional_scope->cond_referenced_var_ids;
            $assigned_in_conditional_var_ids = $if_conditional_scope->assigned_in_conditional_var_ids;
        } catch (Scope_Analysis_Exception) {
            return false;
        }
        $mixed_var_ids = [];
        foreach ($elseif_context->vars_in_scope as $var_id => $type) {
            if ($type->has_mixed()) {
                $mixed_var_ids[] = $var_id;
            }
        }
        $elseif_cond_id = spl_object_id($elseif->cond);
        $elseif_clauses = Formula_Generator::get_formula($elseif_cond_id, $elseif_cond_id, $elseif->cond, $else_context->self, $statements_analyzer, $codebase);
        if (count($elseif_clauses) > 200) {
            $elseif_clauses = [];
        }
        $elseif_clauses_handled = [];
        foreach ($elseif_clauses as $clause) {
            $keys = array_keys($clause->possibilities);
            $mixed_var_ids = array_diff($mixed_var_ids, $keys);
            foreach ($keys as $key) {
                foreach ($mixed_var_ids as $mixed_var_id) {
                    if (preg_match('/^' . preg_quote($mixed_var_id, '/') . '(\[|-)/', $key)) {
                        $clause = new Clause([], $elseif_cond_id, $elseif_cond_id, true);
                        break 2;
                    }
                }
            }
            $elseif_clauses_handled[] = $clause;
        }
        $elseif_clauses = $elseif_clauses_handled;
        $entry_clauses = [];
        foreach ($if_conditional_scope->entry_clauses as $c) {
            foreach ($c->possibilities as $key => $_value) {
                foreach ($assigned_in_conditional_var_ids as $conditional_assigned_var_id => $_) {
                    if (preg_match('/^' . preg_quote($conditional_assigned_var_id, '/') . '(\[|-|$)/', $key)) {
                        $c = new Clause([], $elseif_cond_id, $elseif_cond_id, true);
                        break 2;
                    }
                }
            }
            $entry_clauses[] = $c;
        }
        // this will see whether any of the clauses in set A conflict with the clauses in set B
        Algebra_Analyzer::check_for_paradox($entry_clauses, $elseif_clauses, $statements_analyzer, $elseif->cond, $assigned_in_conditional_var_ids);
        $elseif_clauses = Algebra::simplify_cnf($elseif_clauses);
        $elseif_context->clauses = $entry_clauses ? Algebra::simplify_cnf([...$entry_clauses, ...$elseif_clauses]) : $elseif_clauses;
        if ($elseif_context->reconciled_expression_clauses) {
            $reconciled_expression_clauses = $elseif_context->reconciled_expression_clauses;
            $elseif_context->clauses = array_values(array_filter($elseif_context->clauses, static fn(Clause $c): bool => !in_array($c->hash, $reconciled_expression_clauses, true)));
        }
        $active_elseif_types = [];
        try {
            if (array_any($entry_clauses, static fn(Clause $clause): bool => (bool) $clause->possibilities)) {
                $omit_keys = array_reduce(
                    $entry_clauses,
                    /**
                     * @param array<string> $carry
                     * @return array<string>
                     */
                    static fn(array $carry, Clause $clause): array => array_merge($carry, array_keys($clause->possibilities)),
                    []
                );
                $omit_keys = array_combine($omit_keys, $omit_keys);
                $omit_keys = array_diff_key($omit_keys, Algebra::get_truths_from_formula($entry_clauses));
                $cond_referenced_var_ids = array_diff_key($cond_referenced_var_ids, $omit_keys);
            }
            $reconcilable_elseif_types = Algebra::get_truths_from_formula($elseif_context->clauses, spl_object_id($elseif->cond), $cond_referenced_var_ids, $active_elseif_types);
            $negated_elseif_types = Algebra::get_truths_from_formula(Algebra::negate_formula($elseif_clauses));
        } catch (Complicated_Expression_Exception) {
            $reconcilable_elseif_types = [];
            $negated_elseif_types = [];
        }
        $all_negated_vars = array_unique([...array_keys($negated_elseif_types), ...array_keys($if_scope->negated_types)]);
        foreach ($all_negated_vars as $var_id) {
            if (isset($negated_elseif_types[$var_id])) {
                if (isset($if_scope->negated_types[$var_id])) {
                    $if_scope->negated_types[$var_id] = [...$if_scope->negated_types[$var_id], ...$negated_elseif_types[$var_id]];
                } else {
                    $if_scope->negated_types[$var_id] = $negated_elseif_types[$var_id];
                }
            }
        }
        $newly_reconciled_var_ids = [];
        // if the elseif has an || in the conditional, we cannot easily reason about it
        if ($reconcilable_elseif_types) {
            [$elseif_context->vars_in_scope, $elseif_context->references_in_scope] = Reconciler::reconcile_keyed_types($reconcilable_elseif_types, $active_elseif_types, $elseif_context->vars_in_scope, $elseif_context->references_in_scope, $newly_reconciled_var_ids, $cond_referenced_var_ids, $statements_analyzer, $statements_analyzer->get_template_type_map() ?: [], $elseif_context->inside_loop, new Code_Location($statements_analyzer->get_source(), $elseif->cond instanceof Php_Parser\Node\Expr\Boolean_Not ? $elseif->cond->expr : $elseif->cond, $outer_context->include_location));
            if ($newly_reconciled_var_ids) {
                $elseif_context->clauses = Context::remove_reconciled_clauses($elseif_context->clauses, $newly_reconciled_var_ids)[0];
                foreach ($newly_reconciled_var_ids as $changed_var_id => $_) {
                    foreach ($elseif_context->vars_in_scope as $var_id => $_) {
                        if (preg_match('/' . preg_quote($changed_var_id, '/') . '[\]\[\-]/', $var_id) && !array_key_exists($var_id, $newly_reconciled_var_ids) && !array_key_exists($var_id, $cond_referenced_var_ids)) {
                            $elseif_context->remove_possible_reference($var_id);
                        }
                    }
                }
            }
        }
        $pre_stmts_assigned_var_ids = $elseif_context->assigned_var_ids;
        $elseif_context->assigned_var_ids = [];
        $pre_stmts_possibly_assigned_var_ids = $elseif_context->possibly_assigned_var_ids;
        $elseif_context->possibly_assigned_var_ids = [];
        if ($statements_analyzer->analyze($elseif->stmts, $elseif_context) === false) {
            return false;
        }
        foreach ($elseif_context->parent_remove_vars as $var_id => $_) {
            $outer_context->remove_var_from_conflicting_clauses($var_id);
        }
        /** @var array<string, int> */
        $new_stmts_assigned_var_ids = $elseif_context->assigned_var_ids;
        $elseif_context->assigned_var_ids = $pre_stmts_assigned_var_ids + $new_stmts_assigned_var_ids;
        /** @var array<string, bool> */
        $new_stmts_possibly_assigned_var_ids = $elseif_context->possibly_assigned_var_ids;
        $elseif_context->possibly_assigned_var_ids = $pre_stmts_possibly_assigned_var_ids + $new_stmts_possibly_assigned_var_ids;
        foreach ($elseif_context->byref_constraints as $var_id => $byref_constraint) {
            if (isset($outer_context->byref_constraints[$var_id]) && ($outer_constraint_type = $outer_context->byref_constraints[$var_id]->type) && $byref_constraint->type && !Union_Type_Comparator::is_contained_by($codebase, $byref_constraint->type, $outer_constraint_type)) {
                Issue_Buffer::maybe_add(new Conflicting_Reference_Constraint('There is more than one pass-by-reference constraint on ' . $var_id, new Code_Location($statements_analyzer, $elseif, $outer_context->include_location, true)), $statements_analyzer->get_suppressed_issues());
            } else {
                $outer_context->byref_constraints[$var_id] = $byref_constraint;
            }
        }
        $final_actions = Scope_Analyzer::get_control_actions($elseif->stmts, $statements_analyzer->node_data, []);
        // has a return/throw at end
        $has_ending_statements = $final_actions === [Scope_Analyzer::ACTION_END];
        $has_leaving_statements = $has_ending_statements || count($final_actions) && !in_array(Scope_Analyzer::ACTION_NONE, $final_actions, true);
        $has_break_statement = $final_actions === [Scope_Analyzer::ACTION_BREAK];
        $has_continue_statement = $final_actions === [Scope_Analyzer::ACTION_CONTINUE];
        $if_scope->final_actions = array_merge($final_actions, $if_scope->final_actions);
        // update the parent context as necessary
        if (!$has_leaving_statements) {
            If_Analyzer::update_if_scope($codebase, $if_scope, $elseif_context, $outer_context, array_merge($new_stmts_assigned_var_ids, $assigned_in_conditional_var_ids), $new_stmts_possibly_assigned_var_ids, $newly_reconciled_var_ids);
            $reasonable_clause_count = count($if_scope->reasonable_clauses);
            if ($reasonable_clause_count && $reasonable_clause_count < 20000 && $elseif_clauses) {
                $if_scope->reasonable_clauses = Algebra::combine_ored_clauses($if_scope->reasonable_clauses, $elseif_clauses, $elseif_cond_id);
            } else {
                $if_scope->reasonable_clauses = [];
            }
        } else {
            $if_scope->reasonable_clauses = [];
        }
        if ($negated_elseif_types) {
            if ($has_leaving_statements) {
                $newly_reconciled_var_ids = [];
                $implied_outer_context = clone $elseif_context;
                [$implied_outer_context->vars_in_scope, $implied_outer_context->references_in_scope] = Reconciler::reconcile_keyed_types($negated_elseif_types, [], $pre_conditional_context->vars_in_scope, $pre_conditional_context->references_in_scope, $newly_reconciled_var_ids, [], $statements_analyzer, $statements_analyzer->get_template_type_map() ?: [], $elseif_context->inside_loop, new Code_Location($statements_analyzer->get_source(), $elseif, $outer_context->include_location));
            }
        }
        if (!$has_ending_statements) {
            $vars_possibly_in_scope = array_diff_key($elseif_context->vars_possibly_in_scope, $outer_context->vars_possibly_in_scope);
            $possibly_assigned_var_ids = $new_stmts_possibly_assigned_var_ids;
            if ($has_leaving_statements && $elseif_context->loop_scope) {
                if (!$has_continue_statement && !$has_break_statement) {
                    $if_scope->new_vars_possibly_in_scope = [...$vars_possibly_in_scope, ...$if_scope->new_vars_possibly_in_scope];
                    $if_scope->possibly_assigned_var_ids = array_merge($possibly_assigned_var_ids, $if_scope->possibly_assigned_var_ids);
                }
                $elseif_context->loop_scope->vars_possibly_in_scope = [...$vars_possibly_in_scope, ...$elseif_context->loop_scope->vars_possibly_in_scope];
            } elseif (!$has_leaving_statements) {
                $if_scope->new_vars_possibly_in_scope = [...$vars_possibly_in_scope, ...$if_scope->new_vars_possibly_in_scope];
                $if_scope->possibly_assigned_var_ids = array_merge($possibly_assigned_var_ids, $if_scope->possibly_assigned_var_ids);
            }
        }
        if ($outer_context->collect_exceptions) {
            $outer_context->merge_exceptions($elseif_context);
        }
        try {
            $if_scope->negated_clauses = Algebra::simplify_cnf([...$if_scope->negated_clauses, ...Algebra::negate_formula($elseif_clauses)]);
        } catch (Complicated_Expression_Exception) {
            $if_scope->negated_clauses = [];
        }
        // Track references set in the elseif to make sure they aren't reused later
        $outer_context->update_references_possibly_from_confusing_scope($elseif_context, $statements_analyzer);
        return null;
    }
}