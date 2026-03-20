<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Block;

use Php_Parser;
use Psalm\Context;
use Psalm\Internal\Algebra;
use Psalm\Internal\Analyzer\Scope_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Expression_Identifier;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Scope\Switch_Scope;
use Psalm\Type;
use Psalm\Type\Reconciler;
use SplFixedArray;
use function array_merge;
use function count;
use function in_array;
/**
 * @internal
 */
final class Switch_Analyzer
{
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Stmt\Switch_ $stmt, Context $context): void
    {
        $codebase = $statements_analyzer->get_codebase();
        $was_inside_conditional = $context->inside_conditional;
        $context->inside_conditional = true;
        if (Expression_Analyzer::analyze($statements_analyzer, $stmt->cond, $context) === false) {
            $context->inside_conditional = $was_inside_conditional;
            return;
        }
        $context->inside_conditional = $was_inside_conditional;
        $switch_var_id = Expression_Identifier::get_extended_var_id($stmt->cond, null, $statements_analyzer);
        if (!$switch_var_id && ($stmt->cond instanceof Php_Parser\Node\Expr\Func_Call || $stmt->cond instanceof Php_Parser\Node\Expr\Method_Call || $stmt->cond instanceof Php_Parser\Node\Expr\Static_Call)) {
            $switch_var_id = '$__tmp_switch__' . (int) $stmt->cond->get_attribute('startFilePos');
            $condition_type = $statements_analyzer->node_data->get_type($stmt->cond) ?? Type::get_mixed();
            $context->vars_in_scope[$switch_var_id] = $condition_type;
        }
        $original_context = clone $context;
        // the last statement always breaks, by default
        $last_case_exit_type = 'break';
        $case_exit_types = new SplFixedArray(count($stmt->cases));
        $has_default = false;
        $case_action_map = [];
        // create a map of case statement -> ultimate exit type
        for ($i = count($stmt->cases) - 1; $i >= 0; --$i) {
            $case = $stmt->cases[$i];
            $case_actions = $case_action_map[$i] = Scope_Analyzer::get_control_actions($case->stmts, $statements_analyzer->node_data, ['switch']);
            if (!in_array(Scope_Analyzer::ACTION_NONE, $case_actions, true)) {
                if ($case_actions === [Scope_Analyzer::ACTION_END]) {
                    $last_case_exit_type = 'return_throw';
                } elseif ($case_actions === [Scope_Analyzer::ACTION_CONTINUE]) {
                    $last_case_exit_type = 'continue';
                } elseif (in_array(Scope_Analyzer::ACTION_LEAVE_SWITCH, $case_actions, true)) {
                    $last_case_exit_type = 'break';
                }
            } elseif (count($case_actions) !== 1) {
                $last_case_exit_type = 'hybrid';
            }
            $case_exit_types[$i] = $last_case_exit_type;
        }
        $switch_scope = new Switch_Scope();
        $was_caching_assertions = $statements_analyzer->node_data->cache_assertions;
        $statements_analyzer->node_data->cache_assertions = false;
        $all_options_returned = true;
        for ($i = 0, $l = count($stmt->cases); $i < $l; $i++) {
            $case = $stmt->cases[$i];
            /** @var string */
            $case_exit_type = $case_exit_types[$i];
            if ($case_exit_type !== 'return_throw') {
                $all_options_returned = false;
            }
            $case_actions = $case_action_map[$i];
            if (!$case->cond) {
                $has_default = true;
            }
            if (Switch_Case_Analyzer::analyze($statements_analyzer, $codebase, $stmt, $switch_var_id, $case, $context, $original_context, $case_exit_type, $case_actions, $i === $l - 1, $switch_scope) === false) {
                return;
            }
        }
        $all_options_matched = $has_default;
        if (!$has_default && $switch_scope->negated_clauses && $switch_var_id) {
            $entry_clauses = Algebra::simplify_cnf([...$original_context->clauses, ...$switch_scope->negated_clauses]);
            $reconcilable_if_types = Algebra::get_truths_from_formula($entry_clauses);
            // if the if has an || in the conditional, we cannot easily reason about it
            if ($reconcilable_if_types && isset($reconcilable_if_types[$switch_var_id])) {
                $changed_var_ids = [];
                [$case_vars_in_scope_reconciled, $_] = Reconciler::reconcile_keyed_types($reconcilable_if_types, [], $original_context->vars_in_scope, $original_context->references_in_scope, $changed_var_ids, [], $statements_analyzer, [], $original_context->inside_loop);
                if (isset($case_vars_in_scope_reconciled[$switch_var_id]) && $case_vars_in_scope_reconciled[$switch_var_id]->is_never()) {
                    $all_options_matched = true;
                }
            }
        }
        if ($was_caching_assertions) {
            $statements_analyzer->node_data->cache_assertions = true;
        }
        // only update vars if there is a default or all possible cases accounted for
        // if the default has a throw/return/continue, that should be handled above
        if ($all_options_matched) {
            if ($switch_scope->new_vars_in_scope) {
                $context->vars_in_scope = array_merge($context->vars_in_scope, $switch_scope->new_vars_in_scope);
            }
            if ($switch_scope->redefined_vars) {
                $context->vars_in_scope = array_merge($context->vars_in_scope, $switch_scope->redefined_vars);
            }
            if ($switch_scope->possibly_redefined_vars) {
                foreach ($switch_scope->possibly_redefined_vars as $var_id => $type) {
                    if (!isset($switch_scope->redefined_vars[$var_id]) && !isset($switch_scope->new_vars_in_scope[$var_id]) && isset($context->vars_in_scope[$var_id])) {
                        $context->vars_in_scope[$var_id] = Type::combine_union_types($type, $context->vars_in_scope[$var_id]);
                    }
                }
            }
            $stmt->set_attribute('allMatched', true);
        } elseif ($switch_scope->possibly_redefined_vars) {
            foreach ($switch_scope->possibly_redefined_vars as $var_id => $type) {
                if (isset($context->vars_in_scope[$var_id])) {
                    $context->vars_in_scope[$var_id] = Type::combine_union_types($type, $context->vars_in_scope[$var_id]);
                }
            }
        }
        if ($switch_scope->new_assigned_var_ids) {
            $context->assigned_var_ids += $switch_scope->new_assigned_var_ids;
        }
        $context->vars_possibly_in_scope = [...$context->vars_possibly_in_scope, ...$switch_scope->new_vars_possibly_in_scope];
        //a switch can't return in all options without a default
        $context->has_returned = $all_options_returned && $has_default;
    }
}