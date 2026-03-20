<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Block;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Exception\Complicated_Expression_Exception;
use Psalm\Internal\Algebra;
use Psalm\Internal\Algebra\Formula_Generator;
use Psalm\Internal\Analyzer\Algebra_Analyzer;
use Psalm\Internal\Analyzer\Scope_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Php_Visitor\Condition_Cloning_Visitor;
use Psalm\Internal\Php_Visitor\Type_Mapping_Visitor;
use Psalm\Internal\Scope\Case_Scope;
use Psalm\Internal\Scope\Switch_Scope;
use Psalm\Issue\Continue_Outside_Loop;
use Psalm\Issue\Paradoxical_Condition;
use Psalm\Issue_Buffer;
use Psalm\Node\Expr\Binary_Op\Virtual_Boolean_Or;
use Psalm\Node\Expr\Binary_Op\Virtual_Equal;
use Psalm\Node\Expr\Binary_Op\Virtual_Identical;
use Psalm\Node\Expr\Virtual_Array;
use Psalm\Node\Expr\Virtual_Boolean_Not;
use Psalm\Node\Expr\Virtual_Const_Fetch;
use Psalm\Node\Expr\Virtual_Func_Call;
use Psalm\Node\Expr\Virtual_Variable;
use Psalm\Node\Name\Virtual_Fully_Qualified;
use Psalm\Node\Scalar\Virtual_Int;
use Psalm\Node\Stmt\Virtual_If;
use Psalm\Node\Virtual_Arg;
use Psalm\Node\Virtual_Array_Item;
use Psalm\Node\Virtual_Name;
use Psalm\Type;
use Psalm\Type\Atomic\T_Dependent_Get_Class;
use Psalm\Type\Atomic\T_Dependent_Get_Debug_Type;
use Psalm\Type\Atomic\T_Dependent_Get_Type;
use Psalm\Type\Reconciler;
use function array_diff_key;
use function array_intersect_key;
use function array_merge;
use function count;
use function in_array;
use function is_string;
use function spl_object_id;
use function str_starts_with;
use function substr;
/**
 * @internal
 */
final class Switch_Case_Analyzer
{
    /**
     * @return null|false
     */
    public static function analyze(Statements_Analyzer $statements_analyzer, Codebase $codebase, Php_Parser\Node\Stmt\Switch_ $stmt, ?string $switch_var_id, Php_Parser\Node\Stmt\Case_ $case, Context $context, Context $original_context, string $case_exit_type, array $case_actions, bool $is_last, Switch_Scope $switch_scope): ?bool
    {
        // has a return/throw at end
        $has_ending_statements = $case_actions === [Scope_Analyzer::ACTION_END];
        $has_leaving_statements = $has_ending_statements || count($case_actions) && !in_array(Scope_Analyzer::ACTION_NONE, $case_actions, true);
        $case_context = clone $original_context;
        if ($codebase->alter_code && $case_context->branch_point === null) {
            $case_context->branch_point = (int) $stmt->get_attribute('startFilePos');
        }
        $case_scope = $case_context->case_scope = new Case_Scope($case_context);
        $case_equality_expr = null;
        $old_node_data = $statements_analyzer->node_data;
        $fake_switch_condition = false;
        if ($switch_var_id && str_starts_with($switch_var_id, '$__tmp_switch__')) {
            $switch_condition = new Virtual_Variable(substr($switch_var_id, 1), $stmt->cond->get_attributes());
            $fake_switch_condition = true;
        } else {
            $switch_condition = $stmt->cond;
        }
        if ($case->cond) {
            $was_inside_conditional = $case_context->inside_conditional;
            $case_context->inside_conditional = true;
            if (Expression_Analyzer::analyze($statements_analyzer, $case->cond, $case_context) === false) {
                unset($case_scope->parent_context);
                unset($case_context->case_scope);
                return false;
            }
            $case_context->inside_conditional = $was_inside_conditional;
            $statements_analyzer->node_data = clone $statements_analyzer->node_data;
            $traverser = new Php_Parser\Node_Traverser();
            $traverser->add_visitor(new Condition_Cloning_Visitor($statements_analyzer->node_data));
            /** @var PhpParser\Node\Expr */
            $switch_condition = $traverser->traverse([$switch_condition])[0];
            if ($fake_switch_condition) {
                $statements_analyzer->node_data->set_type($switch_condition, $switch_var_id === null ? Type::get_mixed() : $case_context->vars_in_scope[$switch_var_id] ?? Type::get_mixed());
            }
            if ($switch_condition instanceof Php_Parser\Node\Expr\Variable && is_string($switch_condition->name) && isset($context->vars_in_scope['$' . $switch_condition->name])) {
                $switch_var_type = $context->vars_in_scope['$' . $switch_condition->name];
                $type_statements = [];
                foreach ($switch_var_type->get_atomic_types() as $type) {
                    if ($type instanceof T_Dependent_Get_Class) {
                        $type_statements[] = new Virtual_Func_Call(new Virtual_Name(['get_class']), [new Virtual_Arg(new Virtual_Variable(substr($type->typeof, 1), $stmt->cond->get_attributes()), false, false, $stmt->cond->get_attributes())], $stmt->cond->get_attributes());
                    } elseif ($type instanceof T_Dependent_Get_Type) {
                        $type_statements[] = new Virtual_Func_Call(new Virtual_Name(['gettype']), [new Virtual_Arg(new Virtual_Variable(substr($type->typeof, 1), $stmt->cond->get_attributes()), false, false, $stmt->cond->get_attributes())], $stmt->cond->get_attributes());
                    } elseif ($type instanceof T_Dependent_Get_Debug_Type) {
                        $type_statements[] = new Virtual_Func_Call(new Virtual_Name(['get_debug_type']), [new Virtual_Arg(new Virtual_Variable(substr($type->typeof, 1), $stmt->cond->get_attributes()), false, false, $stmt->cond->get_attributes())], $stmt->cond->get_attributes());
                    } else {
                        $type_statements = null;
                        break;
                    }
                }
                if ($type_statements && count($type_statements) === 1) {
                    $switch_condition = $type_statements[0];
                    if ($fake_switch_condition) {
                        $statements_analyzer->node_data->set_type($switch_condition, $switch_var_id === null ? Type::get_mixed() : $case_context->vars_in_scope[$switch_var_id] ?? Type::get_mixed());
                    }
                }
            }
            if ($switch_condition instanceof Php_Parser\Node\Expr\Const_Fetch && $switch_condition->name->get_parts() === ['true']) {
                $case_equality_expr = $case->cond;
            } elseif (($switch_condition_type = $statements_analyzer->node_data->get_type($switch_condition)) && ($case_cond_type = $statements_analyzer->node_data->get_type($case->cond)) && ($switch_condition_type->is_string() && $case_cond_type->is_string() || $switch_condition_type->is_int() && $case_cond_type->is_int() || $switch_condition_type->is_float() && $case_cond_type->is_float())) {
                $case_equality_expr = new Virtual_Identical($switch_condition, $case->cond, $case->cond->get_attributes());
            } else {
                $case_equality_expr = new Virtual_Equal($switch_condition, $case->cond, $case->cond->get_attributes());
            }
        }
        $continue_case_equality_expr = false;
        if ($case->stmts) {
            $case_stmts = array_merge($switch_scope->leftover_statements, $case->stmts);
        } else {
            $continue_case_equality_expr = count($switch_scope->leftover_statements) === 1;
            $case_stmts = $switch_scope->leftover_statements;
        }
        if (!$has_leaving_statements && !$is_last) {
            if (!$case_equality_expr) {
                $case_equality_expr = new Virtual_Func_Call(new Virtual_Fully_Qualified(['rand']), [new Virtual_Arg(new Virtual_Int(0)), new Virtual_Arg(new Virtual_Int(1))], $case->get_attributes());
            }
            $switch_scope->leftover_case_equality_expr = $switch_scope->leftover_case_equality_expr ? new Virtual_Boolean_Or($switch_scope->leftover_case_equality_expr, $case_equality_expr, $case->cond ? $case->cond->get_attributes() : $case->get_attributes()) : $case_equality_expr;
            if ($continue_case_equality_expr && $switch_scope->leftover_statements[0] instanceof Php_Parser\Node\Stmt\If_) {
                $case_if_stmt = $switch_scope->leftover_statements[0];
                $case_if_stmt->cond = $switch_scope->leftover_case_equality_expr;
            } else {
                $case_if_stmt = new Virtual_If($switch_scope->leftover_case_equality_expr, ['stmts' => $case_stmts], $case->get_attributes());
                $switch_scope->leftover_statements = [$case_if_stmt];
            }
            unset($case_scope->parent_context);
            unset($case_context->case_scope);
            $statements_analyzer->node_data = $old_node_data;
            return null;
        }
        if ($switch_scope->leftover_case_equality_expr) {
            $case_or_default_equality_expr = $case_equality_expr;
            if (!$case_or_default_equality_expr) {
                $case_or_default_equality_expr = new Virtual_Func_Call(new Virtual_Fully_Qualified(['rand']), [new Virtual_Arg(new Virtual_Int(0)), new Virtual_Arg(new Virtual_Int(1))], $case->get_attributes());
            }
            $case_equality_expr = new Virtual_Boolean_Or($switch_scope->leftover_case_equality_expr, $case_or_default_equality_expr, $case_or_default_equality_expr->get_attributes());
        }
        if ($case_equality_expr && $switch_condition instanceof Php_Parser\Node\Expr\Variable && is_string($switch_condition->name) && isset($context->vars_in_scope['$' . $switch_condition->name])) {
            $new_case_equality_expr = self::simplify_case_equality_expression($case_equality_expr, $switch_condition);
            if ($new_case_equality_expr) {
                $was_inside_conditional = $case_context->inside_conditional;
                $case_context->inside_conditional = true;
                Expression_Analyzer::analyze($statements_analyzer, $new_case_equality_expr->get_args()[1]->value, $case_context);
                $case_context->inside_conditional = $was_inside_conditional;
                $case_equality_expr = $new_case_equality_expr;
            }
        }
        $case_context->break_types[] = 'switch';
        $switch_scope->leftover_statements = [];
        $switch_scope->leftover_case_equality_expr = null;
        $case_clauses = [];
        if ($case_equality_expr) {
            $case_equality_expr_id = spl_object_id($case_equality_expr);
            $case_clauses = Formula_Generator::get_formula($case_equality_expr_id, $case_equality_expr_id, $case_equality_expr, $context->self, $statements_analyzer, $codebase, false, false);
        }
        if ($switch_scope->negated_clauses && count($switch_scope->negated_clauses) < 50) {
            $entry_clauses = Algebra::simplify_cnf([...$original_context->clauses, ...$switch_scope->negated_clauses]);
        } else {
            $entry_clauses = $original_context->clauses;
        }
        if ($case_clauses && $case->cond) {
            // this will see whether any of the clauses in set A conflict with the clauses in set B
            Algebra_Analyzer::check_for_paradox($entry_clauses, $case_clauses, $statements_analyzer, $case->cond, []);
            if (count($entry_clauses) + count($case_clauses) < 50) {
                $case_context->clauses = Algebra::simplify_cnf([...$entry_clauses, ...$case_clauses]);
            } else {
                $case_context->clauses = [...$entry_clauses, ...$case_clauses];
            }
        } else {
            $case_context->clauses = $entry_clauses;
        }
        $reconcilable_if_types = Algebra::get_truths_from_formula($case_context->clauses);
        // if the if has an || in the conditional, we cannot easily reason about it
        if ($reconcilable_if_types) {
            $changed_var_ids = [];
            $suppressed_issues = $statements_analyzer->get_suppressed_issues();
            if (!in_array('RedundantCondition', $suppressed_issues, true)) {
                $statements_analyzer->add_suppressed_issues(['RedundantCondition']);
            }
            if (!in_array('RedundantConditionGivenDocblockType', $suppressed_issues, true)) {
                $statements_analyzer->add_suppressed_issues(['RedundantConditionGivenDocblockType']);
            }
            [$case_vars_in_scope_reconciled, $case_references_in_scope_reconciled] = Reconciler::reconcile_keyed_types($reconcilable_if_types, [], $case_context->vars_in_scope, $case_context->references_in_scope, $changed_var_ids, $case->cond && $switch_var_id ? [$switch_var_id => true] : [], $statements_analyzer, [], $case_context->inside_loop, new Code_Location($statements_analyzer->get_source(), $case->cond ?? $case, $context->include_location));
            if (!in_array('RedundantCondition', $suppressed_issues, true)) {
                $statements_analyzer->remove_suppressed_issues(['RedundantCondition']);
            }
            if (!in_array('RedundantConditionGivenDocblockType', $suppressed_issues, true)) {
                $statements_analyzer->remove_suppressed_issues(['RedundantConditionGivenDocblockType']);
            }
            $case_context->vars_in_scope = $case_vars_in_scope_reconciled;
            $case_context->references_in_scope = $case_references_in_scope_reconciled;
            foreach ($reconcilable_if_types as $var_id => $_) {
                $case_context->vars_possibly_in_scope[$var_id] = true;
            }
            if ($changed_var_ids) {
                $case_context->clauses = Context::remove_reconciled_clauses($case_context->clauses, $changed_var_ids)[0];
            }
        }
        if ($case_clauses && $case_equality_expr) {
            try {
                $negated_case_clauses = Algebra::negate_formula($case_clauses);
            } catch (Complicated_Expression_Exception) {
                $case_equality_expr_id = spl_object_id($case_equality_expr);
                try {
                    $negated_case_clauses = Formula_Generator::get_formula($case_equality_expr_id, $case_equality_expr_id, new Virtual_Boolean_Not($case_equality_expr), $context->self, $statements_analyzer, $codebase, false, false);
                } catch (Complicated_Expression_Exception) {
                    $negated_case_clauses = [];
                }
            }
            $switch_scope->negated_clauses = [...$switch_scope->negated_clauses, ...$negated_case_clauses];
        }
        $statements_analyzer->analyze($case_stmts, $case_context);
        $traverser = new Php_Parser\Node_Traverser();
        $traverser->add_visitor(new Type_Mapping_Visitor($statements_analyzer->node_data, $old_node_data));
        $traverser->traverse([$case]);
        $statements_analyzer->node_data = $old_node_data;
        if ($case_exit_type !== 'return_throw') {
            if (self::handle_non_returning_case($statements_analyzer, $switch_var_id, $case, $context, $case_context, $original_context, $case_exit_type, $switch_scope) === false) {
                unset($case_scope->parent_context);
                unset($case_context->case_scope);
                return false;
            }
        }
        // augment the information with data from break statements
        if ($case_scope->break_vars !== null) {
            if ($switch_scope->possibly_redefined_vars === null) {
                $switch_scope->possibly_redefined_vars = array_intersect_key($case_scope->break_vars, $context->vars_in_scope);
            } else {
                foreach ($case_scope->break_vars as $var_id => $type) {
                    if (isset($context->vars_in_scope[$var_id])) {
                        $switch_scope->possibly_redefined_vars[$var_id] = Type::combine_union_types($type, $switch_scope->possibly_redefined_vars[$var_id] ?? null);
                    }
                }
            }
            if ($switch_scope->new_vars_in_scope !== null) {
                foreach ($switch_scope->new_vars_in_scope as $var_id => $type) {
                    if (isset($case_scope->break_vars[$var_id])) {
                        if (!isset($case_context->vars_in_scope[$var_id])) {
                            unset($switch_scope->new_vars_in_scope[$var_id]);
                        } else {
                            $switch_scope->new_vars_in_scope[$var_id] = Type::combine_union_types($case_scope->break_vars[$var_id], $type);
                        }
                    } else {
                        unset($switch_scope->new_vars_in_scope[$var_id]);
                    }
                }
            }
            if ($switch_scope->redefined_vars !== null) {
                foreach ($switch_scope->redefined_vars as $var_id => $type) {
                    if (isset($case_scope->break_vars[$var_id])) {
                        $switch_scope->redefined_vars[$var_id] = Type::combine_union_types($case_scope->break_vars[$var_id], $type);
                    } else {
                        unset($switch_scope->redefined_vars[$var_id]);
                    }
                }
            }
        }
        unset($case_scope->parent_context);
        unset($case_context->case_scope);
        return null;
    }
    /**
     * @return null|false
     */
    private static function handle_non_returning_case(Statements_Analyzer $statements_analyzer, ?string $switch_var_id, Php_Parser\Node\Stmt\Case_ $case, Context $context, Context $case_context, Context $original_context, string $case_exit_type, Switch_Scope $switch_scope): ?bool
    {
        if (!$case->cond && $switch_var_id && isset($case_context->vars_in_scope[$switch_var_id]) && $case_context->vars_in_scope[$switch_var_id]->is_never()) {
            if (Issue_Buffer::accepts(new Paradoxical_Condition('All possible case statements have been met, default is impossible here', new Code_Location($statements_analyzer->get_source(), $case)), $statements_analyzer->get_suppressed_issues())) {
                return false;
            }
        }
        // if we're leaving this block, add vars to outer for loop scope
        if ($case_exit_type === 'continue') {
            if (!$context->loop_scope) {
                if (Issue_Buffer::accepts(new Continue_Outside_Loop('Continue called when not in loop', new Code_Location($statements_analyzer->get_source(), $case)))) {
                    return false;
                }
            }
        } else {
            $case_redefined_vars = $case_context->get_redefined_vars($original_context->vars_in_scope);
            if ($switch_scope->possibly_redefined_vars === null) {
                $switch_scope->possibly_redefined_vars = $case_redefined_vars;
            } else {
                foreach ($case_redefined_vars as $var_id => $type) {
                    $switch_scope->possibly_redefined_vars[$var_id] = Type::combine_union_types($type, $switch_scope->possibly_redefined_vars[$var_id] ?? null);
                }
            }
            if ($switch_scope->redefined_vars === null) {
                $switch_scope->redefined_vars = $case_redefined_vars;
            } else {
                foreach ($switch_scope->redefined_vars as $var_id => $type) {
                    if (!isset($case_redefined_vars[$var_id])) {
                        unset($switch_scope->redefined_vars[$var_id]);
                    } else {
                        $switch_scope->redefined_vars[$var_id] = Type::combine_union_types($type, $case_redefined_vars[$var_id]);
                    }
                }
            }
            $context_new_vars = array_diff_key($case_context->vars_in_scope, $context->vars_in_scope);
            if ($switch_scope->new_vars_in_scope === null) {
                $switch_scope->new_vars_in_scope = $context_new_vars;
                $switch_scope->new_vars_possibly_in_scope = array_diff_key($case_context->vars_possibly_in_scope, $context->vars_possibly_in_scope);
            } else {
                foreach ($switch_scope->new_vars_in_scope as $new_var => $type) {
                    if (!$case_context->has_variable($new_var)) {
                        unset($switch_scope->new_vars_in_scope[$new_var]);
                    } else {
                        $switch_scope->new_vars_in_scope[$new_var] = Type::combine_union_types($case_context->vars_in_scope[$new_var], $type);
                    }
                }
                $switch_scope->new_vars_possibly_in_scope = [...array_diff_key($case_context->vars_possibly_in_scope, $context->vars_possibly_in_scope), ...$switch_scope->new_vars_possibly_in_scope];
            }
        }
        if ($context->collect_exceptions) {
            $context->merge_exceptions($case_context);
        }
        return null;
    }
    private static function simplify_case_equality_expression(Php_Parser\Node\Expr $case_equality_expr, Php_Parser\Node\Expr\Variable $var): ?Php_Parser\Node\Expr\Func_Call
    {
        if ($case_equality_expr instanceof Php_Parser\Node\Expr\Binary_Op\Boolean_Or) {
            $nested_or_options = self::get_options_from_nested_or($case_equality_expr, $var);
            if ($nested_or_options) {
                return new Virtual_Func_Call(new Virtual_Fully_Qualified(['in_array']), [new Virtual_Arg($var, false, false, $var->get_attributes()), new Virtual_Arg(new Virtual_Array($nested_or_options, $case_equality_expr->get_attributes()), false, false, $case_equality_expr->get_attributes()), new Virtual_Arg(new Virtual_Const_Fetch(new Virtual_Fully_Qualified(['true'])))]);
            }
        }
        return null;
    }
    /**
     * @param array<PhpParser\Node\ArrayItem> $in_array_values
     * @return ?array<PhpParser\Node\ArrayItem>
     */
    private static function get_options_from_nested_or(Php_Parser\Node\Expr $case_equality_expr, Php_Parser\Node\Expr\Variable $var, array $in_array_values = []): ?array
    {
        if ($case_equality_expr instanceof Php_Parser\Node\Expr\Binary_Op\Identical && $case_equality_expr->left instanceof Php_Parser\Node\Expr\Variable && $case_equality_expr->left->name === $var->name) {
            $in_array_values[] = new Virtual_Array_Item($case_equality_expr->right, null, false, $case_equality_expr->right->get_attributes());
            return $in_array_values;
        }
        if (!$case_equality_expr instanceof Php_Parser\Node\Expr\Binary_Op\Boolean_Or) {
            return null;
        }
        if (!$case_equality_expr->right instanceof Php_Parser\Node\Expr\Binary_Op\Identical || !$case_equality_expr->right->left instanceof Php_Parser\Node\Expr\Variable || $case_equality_expr->right->left->name !== $var->name) {
            return null;
        }
        $in_array_values[] = new Virtual_Array_Item($case_equality_expr->right->right, null, false, $case_equality_expr->right->right->get_attributes());
        return self::get_options_from_nested_or($case_equality_expr->left, $var, $in_array_values);
    }
}