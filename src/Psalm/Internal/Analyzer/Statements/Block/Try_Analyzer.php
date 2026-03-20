<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Block;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Internal\Analyzer\Class_Like_Analyzer;
use Psalm\Internal\Analyzer\Class_Like_Name_Options;
use Psalm\Internal\Analyzer\Scope_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Codebase\Variable_Use_Graph;
use Psalm\Internal\Data_Flow\Data_Flow_Node;
use Psalm\Internal\Scope\Finally_Scope;
use Psalm\Issue\Invalid_Catch;
use Psalm\Issue_Buffer;
use Psalm\Type;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Union;
use UnexpectedValueException;
use function array_intersect_key;
use function array_map;
use function array_merge;
use function in_array;
use function is_string;
use function strtolower;
/**
 * @internal
 */
final class Try_Analyzer
{
    /**
     * @return  false|null
     */
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Stmt\Try_Catch $stmt, Context $context): ?bool
    {
        $catch_actions = [];
        $all_catches_leave = true;
        $codebase = $statements_analyzer->get_codebase();
        /** @var int $i */
        foreach ($stmt->catches as $i => $catch) {
            $catch_actions[$i] = Scope_Analyzer::get_control_actions($catch->stmts, $statements_analyzer->node_data, []);
            $all_catches_leave = $all_catches_leave && !in_array(Scope_Analyzer::ACTION_NONE, $catch_actions[$i], true);
        }
        $existing_thrown_exceptions = $context->possibly_thrown_exceptions;
        /**
         * @var array<string, array<array-key, CodeLocation>> $context->possibly_thrown_exceptions
         */
        $context->possibly_thrown_exceptions = [];
        $old_context = clone $context;
        $try_context = clone $context;
        if ($codebase->alter_code && $try_context->branch_point === null) {
            $try_context->branch_point = (int) $stmt->get_attribute('startFilePos');
        }
        if ($stmt->finally) {
            $try_context->finally_scope = new Finally_Scope($try_context->vars_in_scope);
        }
        $assigned_var_ids = $try_context->assigned_var_ids;
        $context->assigned_var_ids = [];
        $was_inside_try = $context->inside_try;
        $context->inside_try = true;
        if ($statements_analyzer->analyze($stmt->stmts, $context) === false) {
            return false;
        }
        $context->inside_try = $was_inside_try;
        $context->has_returned = false;
        $try_block_control_actions = Scope_Analyzer::get_control_actions($stmt->stmts, $statements_analyzer->node_data, []);
        /** @var array<string, int> */
        $newly_assigned_var_ids = $context->assigned_var_ids;
        $context->assigned_var_ids = array_merge($assigned_var_ids, $newly_assigned_var_ids);
        foreach ($context->vars_in_scope as $var_id => $type) {
            if (!isset($try_context->vars_in_scope[$var_id])) {
                $try_context->vars_in_scope[$var_id] = $type;
                $context->vars_in_scope[$var_id] = $type->set_possibly_undefined(true, true);
            } else {
                $try_context->vars_in_scope[$var_id] = Type::combine_union_types($try_context->vars_in_scope[$var_id], $type);
            }
        }
        if ($try_context->finally_scope) {
            foreach ($context->vars_in_scope as $var_id => $type) {
                $try_context->finally_scope->vars_in_scope[$var_id] = Type::combine_union_types($try_context->finally_scope->vars_in_scope[$var_id] ?? null, $type, $statements_analyzer->get_codebase());
            }
        }
        $try_context->vars_possibly_in_scope = $context->vars_possibly_in_scope;
        $try_context->possibly_thrown_exceptions = $context->possibly_thrown_exceptions;
        $try_leaves_loop = $context->loop_scope && $context->loop_scope->final_actions && !in_array(Scope_Analyzer::ACTION_NONE, $context->loop_scope->final_actions, true);
        if (!$all_catches_leave) {
            foreach ($newly_assigned_var_ids as $assigned_var_id => $_) {
                $context->remove_var_from_conflicting_clauses($assigned_var_id);
            }
        } else {
            foreach ($newly_assigned_var_ids as $assigned_var_id => $_) {
                $try_context->remove_var_from_conflicting_clauses($assigned_var_id);
            }
        }
        // at this point we have two contexts – $context, in which it is assumed that everything was fine,
        // and $try_context - which allows all variables to have the union of the values before and after
        // the try was applied
        $original_context = clone $try_context;
        $issues_to_suppress = ['RedundantCondition', 'RedundantConditionGivenDocblockType', 'TypeDoesNotContainNull', 'TypeDoesNotContainType'];
        $definitely_newly_assigned_var_ids = $newly_assigned_var_ids;
        /** @var int $i */
        foreach ($stmt->catches as $i => $catch) {
            $catch_context = clone $original_context;
            $catch_context->has_returned = false;
            foreach ($catch_context->vars_in_scope as $var_id => $type) {
                if (!isset($old_context->vars_in_scope[$var_id])) {
                    $catch_context->vars_in_scope[$var_id] = $type->set_possibly_undefined($catch_context->vars_in_scope[$var_id]->possibly_undefined, true);
                } else {
                    $catch_context->vars_in_scope[$var_id] = Type::combine_union_types($type, $old_context->vars_in_scope[$var_id]);
                }
            }
            $fq_catch_classes = [];
            if (!$catch->types) {
                throw new UnexpectedValueException('Very bad');
            }
            foreach ($catch->types as $catch_type) {
                $fq_catch_class = Class_Like_Analyzer::get_fqcln_from_name_object($catch_type, $statements_analyzer->get_aliases());
                $fq_catch_class = $codebase->classlikes->get_un_aliased_name($fq_catch_class);
                if ($codebase->alter_code && $fq_catch_class) {
                    $codebase->classlikes->handle_class_like_reference_in_migration($codebase, $statements_analyzer, $catch_type, $fq_catch_class, $context->calling_method_id);
                }
                if ($original_context->check_classes) {
                    Class_Like_Analyzer::check_fully_qualified_class_like_name($statements_analyzer, $fq_catch_class, new Code_Location($statements_analyzer->get_source(), $catch_type, $context->include_location), $context->self, $context->calling_method_id, $statements_analyzer->get_suppressed_issues(), new Class_Like_Name_Options(true));
                }
                if ($codebase->class_exists($fq_catch_class) && strtolower($fq_catch_class) !== 'exception' && !($codebase->class_extends($fq_catch_class, 'Exception') || $codebase->class_implements($fq_catch_class, 'Throwable')) || $codebase->interface_exists($fq_catch_class) && strtolower($fq_catch_class) !== 'throwable' && !$codebase->interface_extends($fq_catch_class, 'Throwable')) {
                    Issue_Buffer::maybe_add(new Invalid_Catch('Class/interface ' . $fq_catch_class . ' cannot be caught', new Code_Location($statements_analyzer->get_source(), $stmt), $fq_catch_class), $statements_analyzer->get_suppressed_issues());
                }
                $fq_catch_classes[] = $fq_catch_class;
            }
            if ($catch_context->collect_exceptions) {
                foreach ($fq_catch_classes as $fq_catch_class) {
                    $fq_catch_class_lower = strtolower($fq_catch_class);
                    foreach ($catch_context->possibly_thrown_exceptions as $exception_fqcln => $_) {
                        $exception_fqcln_lower = strtolower((string) $exception_fqcln);
                        if ($exception_fqcln_lower === $fq_catch_class_lower || $codebase->class_exists($exception_fqcln) && $codebase->class_extends_or_implements($exception_fqcln, $fq_catch_class) || $codebase->interface_exists($exception_fqcln) && $codebase->interface_extends($exception_fqcln, $fq_catch_class)) {
                            unset($original_context->possibly_thrown_exceptions[$exception_fqcln]);
                            unset($context->possibly_thrown_exceptions[$exception_fqcln]);
                            unset($catch_context->possibly_thrown_exceptions[$exception_fqcln]);
                        }
                    }
                }
                $catch_context->possibly_thrown_exceptions = [];
            }
            // discard all clauses because crazy stuff may have happened in try block
            $catch_context->clauses = [];
            if ($catch->var && is_string($catch->var->name)) {
                $catch_var_id = '$' . $catch->var->name;
                $catch_context->vars_in_scope[$catch_var_id] = new Union(array_map(static fn(string $fq_catch_class): T_Named_Object => new T_Named_Object($fq_catch_class, false, false, strtolower($fq_catch_class) !== 'throwable' && $codebase->interface_exists($fq_catch_class) && !$codebase->interface_extends($fq_catch_class, 'Throwable') ? ['Throwable' => new T_Named_Object('Throwable')] : []), $fq_catch_classes));
                // removes dependent vars from $context
                $catch_context->remove_descendents($catch_var_id, $catch_context->vars_in_scope[$catch_var_id], $catch_context->vars_in_scope[$catch_var_id], $statements_analyzer);
                $catch_context->vars_possibly_in_scope[$catch_var_id] = true;
                $location = new Code_Location($statements_analyzer->get_source(), $catch->var);
                if (!$statements_analyzer->has_variable($catch_var_id)) {
                    $statements_analyzer->register_variable($catch_var_id, $location, $catch_context->branch_point);
                } else {
                    $statements_analyzer->register_variable_assignment($catch_var_id, $location);
                }
                if ($statements_analyzer->data_flow_graph) {
                    $catch_var_node = Data_Flow_Node::get_for_assignment($catch_var_id, $location);
                    $catch_context->vars_in_scope[$catch_var_id] = $catch_context->vars_in_scope[$catch_var_id]->add_parent_nodes([$catch_var_node->id => $catch_var_node]);
                    if ($statements_analyzer->data_flow_graph instanceof Variable_Use_Graph) {
                        $statements_analyzer->data_flow_graph->add_path($catch_var_node, new Data_Flow_Node('variable-use', 'variable use', null), 'variable-use');
                    }
                }
            }
            $suppressed_issues = $statements_analyzer->get_suppressed_issues();
            foreach ($issues_to_suppress as $issue_to_suppress) {
                if (!in_array($issue_to_suppress, $suppressed_issues, true)) {
                    $statements_analyzer->add_suppressed_issues([$issue_to_suppress]);
                }
            }
            $old_catch_assigned_var_ids = $catch_context->assigned_var_ids;
            $catch_context->assigned_var_ids = [];
            $statements_analyzer->analyze($catch->stmts, $catch_context);
            // recalculate in case there's a no-return clause
            $catch_actions[$i] = Scope_Analyzer::get_control_actions($catch->stmts, $statements_analyzer->node_data, []);
            foreach ($issues_to_suppress as $issue_to_suppress) {
                if (!in_array($issue_to_suppress, $suppressed_issues, true)) {
                    $statements_analyzer->remove_suppressed_issues([$issue_to_suppress]);
                }
            }
            /** @var array<string, bool> */
            $new_catch_assigned_var_ids = $catch_context->assigned_var_ids;
            $catch_context->assigned_var_ids += $old_catch_assigned_var_ids;
            if ($catch_context->collect_exceptions) {
                $context->merge_exceptions($catch_context);
            }
            $catch_doesnt_leave_parent_scope = $catch_actions[$i] !== [Scope_Analyzer::ACTION_END] && $catch_actions[$i] !== [Scope_Analyzer::ACTION_CONTINUE] && $catch_actions[$i] !== [Scope_Analyzer::ACTION_BREAK];
            if ($catch_doesnt_leave_parent_scope) {
                $definitely_newly_assigned_var_ids = array_intersect_key($new_catch_assigned_var_ids, $definitely_newly_assigned_var_ids);
                foreach ($catch_context->vars_in_scope as $var_id => $type) {
                    if ($try_block_control_actions === [Scope_Analyzer::ACTION_END]) {
                        $context->vars_in_scope[$var_id] = $type;
                    } elseif (isset($context->vars_in_scope[$var_id])) {
                        $context->vars_in_scope[$var_id] = Type::combine_union_types($context->vars_in_scope[$var_id], $type);
                    }
                }
                $context->vars_possibly_in_scope = array_merge($catch_context->vars_possibly_in_scope, $context->vars_possibly_in_scope);
            } else if ($stmt->finally) {
                $context->vars_possibly_in_scope = array_merge($catch_context->vars_possibly_in_scope, $context->vars_possibly_in_scope);
            }
            if ($try_context->finally_scope) {
                foreach ($catch_context->vars_in_scope as $var_id => &$type) {
                    if (isset($try_context->finally_scope->vars_in_scope[$var_id])) {
                        if ($try_context->finally_scope->vars_in_scope[$var_id] !== $type) {
                            $try_context->finally_scope->vars_in_scope[$var_id] = Type::combine_union_types($try_context->finally_scope->vars_in_scope[$var_id], $type, $statements_analyzer->get_codebase());
                        }
                    } else {
                        $try_context->finally_scope->vars_in_scope[$var_id] = $type->set_possibly_undefined(true, true);
                    }
                }
                unset($type);
            }
        }
        if ($context->loop_scope && !$try_leaves_loop && !in_array(Scope_Analyzer::ACTION_NONE, $context->loop_scope->final_actions, true)) {
            $context->loop_scope->final_actions[] = Scope_Analyzer::ACTION_NONE;
        }
        $finally_has_returned = false;
        if ($stmt->finally) {
            if ($try_context->finally_scope) {
                $finally_context = clone $context;
                $finally_context->assigned_var_ids = [];
                $finally_context->possibly_assigned_var_ids = [];
                $finally_context->vars_in_scope = $try_context->finally_scope->vars_in_scope;
                $statements_analyzer->analyze($stmt->finally->stmts, $finally_context);
                $finally_has_returned = $finally_context->has_returned;
                /** @var string $var_id */
                foreach ($finally_context->assigned_var_ids as $var_id => $_) {
                    if (isset($context->vars_in_scope[$var_id]) && isset($finally_context->vars_in_scope[$var_id])) {
                        $possibly_undefined = $context->vars_in_scope[$var_id]->possibly_undefined && $context->vars_in_scope[$var_id]->possibly_undefined_from_try;
                        $context->vars_in_scope[$var_id] = Type::combine_union_types($context->vars_in_scope[$var_id], $finally_context->vars_in_scope[$var_id], $codebase);
                        if ($possibly_undefined) {
                            /** @psalm-suppress InaccessibleProperty We just created this type */
                            $context->vars_in_scope[$var_id]->possibly_undefined = false;
                            /** @psalm-suppress InaccessibleProperty We just created this type */
                            $context->vars_in_scope[$var_id]->possibly_undefined_from_try = false;
                        }
                    } elseif (isset($finally_context->vars_in_scope[$var_id])) {
                        $context->vars_in_scope[$var_id] = $finally_context->vars_in_scope[$var_id];
                    }
                }
            }
        }
        foreach ($definitely_newly_assigned_var_ids as $var_id => $_) {
            if (!isset($context->vars_in_scope[$var_id])) {
                continue;
            }
            if (!$context->vars_in_scope[$var_id]->possibly_undefined_from_try) {
                continue;
            }
            $context->vars_in_scope[$var_id] = $context->vars_in_scope[$var_id]->set_possibly_undefined(false, false);
        }
        foreach ($existing_thrown_exceptions as $possibly_thrown_exception => $codelocations) {
            foreach ($codelocations as $hash => $codelocation) {
                $context->possibly_thrown_exceptions[$possibly_thrown_exception][$hash] = $codelocation;
            }
        }
        $body_has_returned = !in_array(Scope_Analyzer::ACTION_NONE, $try_block_control_actions, true);
        $context->has_returned = $body_has_returned && $all_catches_leave || $finally_has_returned;
        return null;
    }
}