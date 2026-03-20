<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Internal\Algebra;
use Psalm\Internal\Algebra\Formula_Generator;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Issue\Unhandled_Match_Condition;
use Psalm\Issue_Buffer;
use Psalm\Node\Expr\Binary_Op\Virtual_Identical;
use Psalm\Node\Expr\Virtual_Array;
use Psalm\Node\Expr\Virtual_Const_Fetch;
use Psalm\Node\Expr\Virtual_Func_Call;
use Psalm\Node\Expr\Virtual_New;
use Psalm\Node\Expr\Virtual_Ternary;
use Psalm\Node\Expr\Virtual_Throw;
use Psalm\Node\Expr\Virtual_Variable;
use Psalm\Node\Name\Virtual_Fully_Qualified;
use Psalm\Node\Virtual_Arg;
use Psalm\Node\Virtual_Array_Item;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\T_Enum_Case;
use Psalm\Type\Atomic\T_Literal_Float;
use Psalm\Type\Atomic\T_Literal_Int;
use Psalm\Type\Atomic\T_Literal_String;
use Psalm\Type\Reconciler;
use UnexpectedValueException;
use function array_any;
use function array_map;
use function array_merge;
use function array_reverse;
use function array_shift;
use function count;
use function in_array;
use function spl_object_id;
use function substr;
/**
 * @internal
 */
final class Match_Analyzer
{
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Match_ $stmt, Context $context): bool
    {
        $was_inside_call = $context->inside_call;
        $context->inside_call = true;
        $was_inside_conditional = $context->inside_conditional;
        $context->inside_conditional = true;
        if (Expression_Analyzer::analyze($statements_analyzer, $stmt->cond, $context) === false) {
            $context->inside_conditional = $was_inside_conditional;
            return false;
        }
        $context->inside_conditional = $was_inside_conditional;
        $switch_var_id = Expression_Identifier::get_extended_var_id($stmt->cond, null, $statements_analyzer);
        $match_condition = $stmt->cond;
        if (!$switch_var_id) {
            if ($stmt->cond instanceof Php_Parser\Node\Expr\Func_Call && $stmt->cond->name instanceof Php_Parser\Node\Name && ($stmt->cond->name->get_parts() === ['get_class'] || $stmt->cond->name->get_parts() === ['gettype'] || $stmt->cond->name->get_parts() === ['get_debug_type'] || $stmt->cond->name->get_parts() === ['count'] || $stmt->cond->name->get_parts() === ['sizeof']) && $stmt->cond->get_args()) {
                $first_arg = $stmt->cond->get_args()[0];
                if (!$first_arg->value instanceof Php_Parser\Node\Expr\Variable) {
                    $switch_var_id = '$__tmp_switch__' . (int) $first_arg->value->get_attribute('startFilePos');
                    $condition_type = $statements_analyzer->node_data->get_type($first_arg->value) ?? Type::get_mixed();
                    $context->vars_in_scope[$switch_var_id] = $condition_type;
                    $match_condition = new Virtual_Func_Call($stmt->cond->name, [new Virtual_Arg(new Virtual_Variable(substr($switch_var_id, 1), $first_arg->value->get_attributes()), false, false, $first_arg->get_attributes())], $stmt->cond->get_attributes());
                }
            } elseif ($stmt->cond instanceof Php_Parser\Node\Expr\Class_Const_Fetch && $stmt->cond->name instanceof Php_Parser\Node\Identifier && $stmt->cond->name->to_string() === 'class') {
                // do nothing
            } elseif ($stmt->cond instanceof Php_Parser\Node\Expr\Const_Fetch && $stmt->cond->name->to_string() === 'true') {
                // do nothing
            } else {
                $switch_var_id = '$__tmp_switch__' . (int) $stmt->cond->get_attribute('startFilePos');
                $condition_type = $statements_analyzer->node_data->get_type($stmt->cond) ?? Type::get_mixed();
                $context->vars_in_scope[$switch_var_id] = $condition_type;
                $match_condition = new Virtual_Variable(substr($switch_var_id, 1), $stmt->cond->get_attributes());
            }
        }
        $arms = $stmt->arms;
        $flattened_arms = [];
        $last_arm = null;
        foreach ($arms as $arm) {
            if ($arm->conds === null) {
                $last_arm = $arm;
                continue;
            }
            foreach ($arm->conds as $cond) {
                $flattened_arms[] = new Php_Parser\Node\Match_Arm([$cond], $arm->body, $arm->get_attributes());
            }
        }
        $arms = $flattened_arms;
        $arms = array_reverse($arms);
        $last_arm ??= array_shift($arms);
        if (!$last_arm) {
            Issue_Buffer::maybe_add(new Unhandled_Match_Condition('This match expression does not match anything', new Code_Location($statements_analyzer->get_source(), $match_condition)), $statements_analyzer->get_suppressed_issues());
            return false;
        }
        $old_node_data = $statements_analyzer->node_data;
        $statements_analyzer->node_data = clone $statements_analyzer->node_data;
        if (!$last_arm->conds) {
            $ternary = $last_arm->body;
        } else {
            $ternary = new Virtual_Ternary(self::convert_conds_to_conditional($last_arm->conds, $match_condition, $last_arm->get_attributes()), $last_arm->body, new Virtual_Throw(new Virtual_New(new Virtual_Fully_Qualified('UnhandledMatchError', $stmt->get_attributes()), [], $stmt->get_attributes())), $stmt->get_attributes());
        }
        foreach ($arms as $arm) {
            if (!$arm->conds) {
                continue;
            }
            $ternary = new Virtual_Ternary(self::convert_conds_to_conditional($arm->conds, $match_condition, $arm->get_attributes()), $arm->body, $ternary, $arm->get_attributes());
        }
        $suppressed_issues = $statements_analyzer->get_suppressed_issues();
        if (!in_array('RedundantCondition', $suppressed_issues, true)) {
            $statements_analyzer->add_suppressed_issues(['RedundantCondition']);
        }
        if (!in_array('RedundantConditionGivenDocblockType', $suppressed_issues, true)) {
            $statements_analyzer->add_suppressed_issues(['RedundantConditionGivenDocblockType']);
        }
        $v = Expression_Analyzer::analyze($statements_analyzer, $ternary, $context);
        if (!in_array('RedundantCondition', $suppressed_issues, true)) {
            $statements_analyzer->remove_suppressed_issues(['RedundantCondition']);
        }
        if (!in_array('RedundantConditionGivenDocblockType', $suppressed_issues, true)) {
            $statements_analyzer->remove_suppressed_issues(['RedundantConditionGivenDocblockType']);
        }
        if ($v === false) {
            return false;
        }
        if ($switch_var_id && $last_arm->conds) {
            $codebase = $statements_analyzer->get_codebase();
            $all_conds = $last_arm->conds;
            foreach ($arms as $arm) {
                if (!$arm->conds) {
                    throw new UnexpectedValueException('bad');
                }
                $all_conds = array_merge($arm->conds, $all_conds);
            }
            $all_match_condition = self::convert_conds_to_conditional($all_conds, $match_condition, $match_condition->get_attributes());
            Expression_Analyzer::analyze($statements_analyzer, $all_match_condition, $context);
            $clauses = Formula_Generator::get_formula(spl_object_id($all_match_condition), spl_object_id($all_match_condition), $all_match_condition, $context->self, $statements_analyzer, $codebase, false, false);
            $reconcilable_types = Algebra::get_truths_from_formula(Algebra::negate_formula($clauses));
            // if the if has an || in the conditional, we cannot easily reason about it
            if ($reconcilable_types) {
                $changed_var_ids = [];
                [$vars_in_scope_reconciled, $_] = Reconciler::reconcile_keyed_types($reconcilable_types, [], $context->vars_in_scope, $context->references_in_scope, $changed_var_ids, [], $statements_analyzer, [], $context->inside_loop);
                if (isset($vars_in_scope_reconciled[$switch_var_id])) {
                    $array_literal_types = array_any($vars_in_scope_reconciled[$switch_var_id]->get_atomic_types(), static fn(Atomic $type): bool => $type instanceof T_Literal_Int || $type instanceof T_Literal_String || $type instanceof T_Literal_Float || $type instanceof T_Enum_Case);
                    if ($array_literal_types) {
                        Issue_Buffer::maybe_add(new Unhandled_Match_Condition('This match expression is not exhaustive - consider values ' . $vars_in_scope_reconciled[$switch_var_id]->get_id(), new Code_Location($statements_analyzer->get_source(), $match_condition)), $statements_analyzer->get_suppressed_issues());
                    }
                }
            }
        }
        $stmt_expr_type = $statements_analyzer->node_data->get_type($ternary);
        $old_node_data->set_type($stmt, $stmt_expr_type ?? Type::get_mixed());
        $statements_analyzer->node_data = $old_node_data;
        $context->inside_call = $was_inside_call;
        return true;
    }
    /**
     * @param non-empty-list<PhpParser\Node\Expr> $conds
     * @param array<string, mixed> $attributes
     */
    private static function convert_conds_to_conditional(array $conds, Php_Parser\Node\Expr $match_condition, array $attributes): Php_Parser\Node\Expr
    {
        if (count($conds) === 1) {
            return new Virtual_Identical($match_condition, $conds[0], $attributes);
        }
        $array_items = array_map(static fn(Php_Parser\Node\Expr $cond): Php_Parser\Node\Array_Item => new Virtual_Array_Item($cond, null, false, $cond->get_attributes()), $conds);
        return new Virtual_Func_Call(new Virtual_Fully_Qualified(['in_array']), [new Virtual_Arg($match_condition, false, false, $attributes), new Virtual_Arg(new Virtual_Array($array_items, $attributes), false, false, $attributes), new Virtual_Arg(new Virtual_Const_Fetch(new Virtual_Fully_Qualified(['true']), $attributes), false, false, $attributes)], $attributes);
    }
}