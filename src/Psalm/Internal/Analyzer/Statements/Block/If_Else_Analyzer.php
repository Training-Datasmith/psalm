<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Block;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Exception\Complicated_Expression_Exception;
use Psalm\Exception\Scope_Analysis_Exception;
use Psalm\Internal\Algebra;
use Psalm\Internal\Algebra\Formula_Generator;
use Psalm\Internal\Analyzer\Algebra_Analyzer;
use Psalm\Internal\Analyzer\Function_Like_Analyzer;
use Psalm\Internal\Analyzer\Scope_Analyzer;
use Psalm\Internal\Analyzer\Statements\Block\If_Else\Else_Analyzer;
use Psalm\Internal\Analyzer\Statements\Block\If_Else\Else_If_Analyzer;
use Psalm\Internal\Analyzer\Statements\Block\If_Else\If_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Analyzer\Trait_Analyzer;
use Psalm\Internal\Clause;
use Psalm\Internal\Scope\If_Scope;
use Psalm\Issue_Buffer;
use Psalm\Node\Expr\Virtual_Boolean_Not;
use Psalm\Type;
use Psalm\Type\Reconciler;
use function array_diff;
use function array_filter;
use function array_intersect_key;
use function array_keys;
use function array_merge;
use function array_unique;
use function array_values;
use function count;
use function in_array;
use function preg_match;
use function preg_quote;
use function spl_object_id;
use function substr;
/**
 * @internal
 */
final class If_Else_Analyzer
{
    /**
     * System of type substitution and deletion
     *
     * for example
     *
     * x: A|null
     *
     * if (x)
     *   (x: A)
     *   x = B  -- effects: remove A from the type of x, add B
     * else
     *   (x: null)
     *   x = C  -- effects: remove null from the type of x, add C
     *
     *
     * x: A|null
     *
     * if (!x)
     *   (x: null)
     *   throw new Exception -- effects: remove null from the type of x
     *
     * @return null|false
     */
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Stmt\If_ $stmt, Context $context): ?bool
    {
        $codebase = $statements_analyzer->get_codebase();
        $if_scope = new If_Scope();
        // We need to clone the original context for later use if we're exiting in this if conditional
        if ($stmt->cond instanceof Php_Parser\Node\Expr\Binary_Op || $stmt->cond instanceof Php_Parser\Node\Expr\Boolean_Not && $stmt->cond->expr instanceof Php_Parser\Node\Expr\Binary_Op) {
            $final_actions = Scope_Analyzer::get_control_actions($stmt->stmts, null, []);
            $has_leaving_statements = $final_actions === [Scope_Analyzer::ACTION_END] || count($final_actions) && !in_array(Scope_Analyzer::ACTION_NONE, $final_actions, true);
            if ($has_leaving_statements) {
                $if_scope->post_leaving_if_context = clone $context;
            }
        }
        $branch_point = $context->branch_point ?: (int) $stmt->get_attribute('startFilePos');
        try {
            $if_conditional_scope = If_Conditional_Analyzer::analyze($statements_analyzer, $stmt->cond, $context, $codebase, $if_scope, $branch_point);
            // this is the context for stuff that happens within the `if` block
            $if_context = $if_conditional_scope->if_context;
            // this is the context for stuff that happens after the `if` block
            $post_if_context = $if_conditional_scope->post_if_context;
            $assigned_in_conditional_var_ids = $if_conditional_scope->assigned_in_conditional_var_ids;
        } catch (Scope_Analysis_Exception) {
            return false;
        }
        $mixed_var_ids = [];
        foreach ($if_context->vars_in_scope as $var_id => $type) {
            if ($type->is_mixed() && isset($context->vars_in_scope[$var_id])) {
                $mixed_var_ids[] = $var_id;
            }
        }
        $cond_object_id = spl_object_id($stmt->cond);
        $if_clauses = Formula_Generator::get_formula($cond_object_id, $cond_object_id, $stmt->cond, $context->self, $statements_analyzer, $codebase);
        if (count($if_clauses) > 200) {
            $if_clauses = [];
        }
        $if_clauses_handled = [];
        foreach ($if_clauses as $clause) {
            $keys = array_keys($clause->possibilities);
            $mixed_var_ids = array_diff($mixed_var_ids, $keys);
            foreach ($keys as $key) {
                foreach ($mixed_var_ids as $mixed_var_id) {
                    if (preg_match('/^' . preg_quote($mixed_var_id, '/') . '(\[|-)/', $key)) {
                        $clause = new Clause([], $cond_object_id, $cond_object_id, true);
                        break 2;
                    }
                }
            }
            $if_clauses_handled[] = $clause;
        }
        $if_clauses = $if_clauses_handled;
        $entry_clauses = $context->clauses;
        // this will see whether any of the clauses in set A conflict with the clauses in set B
        Algebra_Analyzer::check_for_paradox($entry_clauses, $if_clauses, $statements_analyzer, $stmt->cond, $assigned_in_conditional_var_ids);
        $if_clauses = Algebra::simplify_cnf($if_clauses);
        $if_context->clauses = $entry_clauses ? Algebra::simplify_cnf([...$entry_clauses, ...$if_clauses]) : $if_clauses;
        if ($if_context->reconciled_expression_clauses) {
            $reconciled_expression_clauses = $if_context->reconciled_expression_clauses;
            $if_context->clauses = array_values(array_filter($if_context->clauses, static fn(Clause $c): bool => !in_array($c->hash, $reconciled_expression_clauses, true)));
            if (count($if_context->clauses) === 1 && $if_context->clauses[0]->wedge && !$if_context->clauses[0]->possibilities) {
                $if_context->clauses = [];
                $if_context->reconciled_expression_clauses = [];
            }
        }
        // define this before we alter local clauses after reconciliation
        $if_scope->reasonable_clauses = $if_context->clauses;
        try {
            $if_scope->negated_clauses = Algebra::negate_formula($if_clauses);
        } catch (Complicated_Expression_Exception) {
            try {
                $if_scope->negated_clauses = Formula_Generator::get_formula($cond_object_id, $cond_object_id, new Virtual_Boolean_Not($stmt->cond), $context->self, $statements_analyzer, $codebase, false);
            } catch (Complicated_Expression_Exception) {
                $if_scope->negated_clauses = [];
            }
        }
        $if_scope->negated_types = Algebra::get_truths_from_formula(Algebra::simplify_cnf([...$context->clauses, ...$if_scope->negated_clauses]));
        $temp_else_context = clone $post_if_context;
        $changed_var_ids = [];
        if ($if_scope->negated_types) {
            [$temp_else_context->vars_in_scope, $temp_else_context->references_in_scope] = Reconciler::reconcile_keyed_types($if_scope->negated_types, [], $temp_else_context->vars_in_scope, $temp_else_context->references_in_scope, $changed_var_ids, [], $statements_analyzer, $statements_analyzer->get_template_type_map() ?: [], $context->inside_loop, $context->check_variables ? new Code_Location($statements_analyzer->get_source(), $stmt->cond instanceof Php_Parser\Node\Expr\Boolean_Not ? $stmt->cond->expr : $stmt->cond, $context->include_location) : null);
        }
        // we calculate the vars redefined in a hypothetical else statement to determine
        // which vars of the if we can safely change
        $pre_assignment_else_redefined_vars = array_intersect_key($temp_else_context->get_redefined_vars($context->vars_in_scope, true), $changed_var_ids);
        // check the if
        if (If_Analyzer::analyze($statements_analyzer, $stmt, $if_scope, $if_conditional_scope, $if_context, $context, $pre_assignment_else_redefined_vars) === false) {
            return false;
        }
        // this has to go on a separate line because the phar compactor messes with precedence
        $scope_to_clone = $if_scope->post_leaving_if_context ?? $post_if_context;
        $else_context = clone $scope_to_clone;
        $else_context->clauses = Algebra::simplify_cnf([...$else_context->clauses, ...$if_scope->negated_clauses]);
        // check the elseifs
        foreach ($stmt->elseifs as $elseif) {
            if (Else_If_Analyzer::analyze($statements_analyzer, $elseif, $if_scope, $else_context, $context, $codebase, $else_context->branch_point ?: (int) $stmt->get_attribute('startFilePos')) === false) {
                return false;
            }
        }
        if ($stmt->else) {
            if ($codebase->alter_code && $else_context->branch_point === null) {
                $else_context->branch_point = (int) $stmt->get_attribute('startFilePos');
            }
        }
        if (Else_Analyzer::analyze($statements_analyzer, $stmt->else, $if_scope, $else_context, $context) === false) {
            return false;
        }
        if (count($if_scope->if_actions) && !in_array(Scope_Analyzer::ACTION_NONE, $if_scope->if_actions, true) && !$stmt->elseifs) {
            $context->clauses = $else_context->clauses;
            foreach ($else_context->vars_in_scope as $var_id => $type) {
                $context->vars_in_scope[$var_id] = $type;
            }
            foreach ($pre_assignment_else_redefined_vars as $var_id => $reconciled_type) {
                $first_appearance = $statements_analyzer->get_first_appearance($var_id);
                if ($first_appearance && isset($post_if_context->vars_in_scope[$var_id]) && $post_if_context->vars_in_scope[$var_id]->has_mixed() && !$reconciled_type->has_mixed()) {
                    if (!$post_if_context->collect_initializations && !$post_if_context->collect_mutations && $statements_analyzer->get_file_path() === $statements_analyzer->get_root_file_path()) {
                        $parent_source = $statements_analyzer->get_source();
                        $functionlike_storage = $parent_source instanceof Function_Like_Analyzer ? $parent_source->get_function_like_storage($statements_analyzer) : null;
                        if (!$functionlike_storage || !$parent_source->get_source() instanceof Trait_Analyzer && !isset($functionlike_storage->param_lookup[substr($var_id, 1)])) {
                            $codebase = $statements_analyzer->get_codebase();
                            $codebase->analyzer->decrement_mixed_count($statements_analyzer->get_file_path());
                        }
                    }
                    Issue_Buffer::remove($statements_analyzer->get_file_path(), 'MixedAssignment', $first_appearance->raw_file_start);
                }
            }
        }
        if ($context->loop_scope) {
            $context->loop_scope->final_actions = array_unique(array_merge($context->loop_scope->final_actions, $if_scope->final_actions));
        }
        $context->vars_possibly_in_scope = [...$context->vars_possibly_in_scope, ...$if_scope->new_vars_possibly_in_scope];
        $context->possibly_assigned_var_ids = [...$context->possibly_assigned_var_ids, ...$if_scope->possibly_assigned_var_ids ?: []];
        // vars can only be defined/redefined if there was an else (defined in every block)
        $context->assigned_var_ids = array_merge($context->assigned_var_ids, $if_scope->assigned_var_ids ?: []);
        if ($if_scope->new_vars) {
            foreach ($if_scope->new_vars as $var_id => &$type) {
                if (isset($context->vars_possibly_in_scope[$var_id]) && $statements_analyzer->data_flow_graph) {
                    $type = $type->add_parent_nodes($statements_analyzer->get_parent_nodes_for_possibly_undefined_variable($var_id));
                }
                $context->vars_in_scope[$var_id] = $type;
            }
            unset($type);
        }
        if ($if_scope->redefined_vars) {
            foreach ($if_scope->redefined_vars as $var_id => $type) {
                $context->vars_in_scope[$var_id] = $type;
                $if_scope->updated_vars[$var_id] = true;
                if ($if_scope->reasonable_clauses) {
                    $if_scope->reasonable_clauses = Context::filter_clauses($var_id, $if_scope->reasonable_clauses, $context->vars_in_scope[$var_id] ?? null, $statements_analyzer);
                }
            }
        }
        if ($if_scope->reasonable_clauses && (count($if_scope->reasonable_clauses) > 1 || !$if_scope->reasonable_clauses[0]->wedge)) {
            $context->clauses = Algebra::simplify_cnf([...$if_scope->reasonable_clauses, ...$context->clauses]);
        }
        foreach ($if_scope->possibly_redefined_vars as $var_id => $type) {
            if (isset($context->vars_in_scope[$var_id])) {
                if (!$type->failed_reconciliation && !isset($if_scope->updated_vars[$var_id])) {
                    $combined_type = Type::combine_union_types($context->vars_in_scope[$var_id], $type, $codebase);
                    if (!$combined_type->equals($context->vars_in_scope[$var_id])) {
                        $context->remove_descendents($var_id, $combined_type);
                    }
                    $context->vars_in_scope[$var_id] = $combined_type;
                } else {
                    $context->vars_in_scope[$var_id] = $context->vars_in_scope[$var_id]->add_parent_nodes($type->parent_nodes);
                }
            }
        }
        if (!in_array(Scope_Analyzer::ACTION_NONE, $if_scope->final_actions, true)) {
            $context->has_returned = true;
        }
        return null;
    }
}