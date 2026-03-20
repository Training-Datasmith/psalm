<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Block;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Exception\Complicated_Expression_Exception;
use Psalm\Internal\Algebra;
use Psalm\Internal\Algebra\Formula_Generator;
use Psalm\Internal\Analyzer\Scope_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Clause;
use Psalm\Internal\Php_Visitor\Assignment_Map_Visitor;
use Psalm\Internal\Php_Visitor\Node_Cleaner_Visitor;
use Psalm\Internal\Scope\Loop_Scope;
use Psalm\Issue_Buffer;
use Psalm\Type;
use Psalm\Type\Reconciler;
use Psalm\Type\Union;
use UnexpectedValueException;
use function array_keys;
use function array_merge;
use function array_unique;
use function count;
use function in_array;
use function is_string;
use function spl_object_id;
/**
 * @internal
 */
final class Loop_Analyzer
{
    /**
     * Checks an array of statements in a loop
     *
     * @param  list<PhpParser\Node\Stmt>    $stmts
     * @param  list<PhpParser\Node\Expr>    $pre_conditions
     * @param  PhpParser\Node\Expr[]        $post_expressions
     * @return false|null
     */
    public static function analyze(Statements_Analyzer $statements_analyzer, array $stmts, array $pre_conditions, array $post_expressions, Loop_Scope $loop_scope, ?Context &$continue_context = null, bool $is_do = false, bool $always_enters_loop = false): ?bool
    {
        $traverser = new Php_Parser\Node_Traverser();
        $loop_context = $loop_scope->loop_context;
        $loop_parent_context = $loop_scope->loop_parent_context;
        $assignment_mapper = new Assignment_Map_Visitor($loop_context->self);
        $traverser->add_visitor($assignment_mapper);
        $traverser->traverse(array_merge($pre_conditions, $stmts, $post_expressions));
        $assignment_map = $assignment_mapper->get_assignment_map();
        $assignment_depth = 0;
        $always_assigned_before_loop_body_vars = [];
        $pre_condition_clauses = [];
        $original_protected_var_ids = $loop_parent_context->protected_var_ids;
        $codebase = $statements_analyzer->get_codebase();
        $inner_do_context = null;
        if ($pre_conditions) {
            foreach ($pre_conditions as $i => $pre_condition) {
                $pre_condition_id = spl_object_id($pre_condition);
                $pre_condition_clauses[$i] = Formula_Generator::get_formula($pre_condition_id, $pre_condition_id, $pre_condition, $loop_context->self, $statements_analyzer, $codebase);
            }
        } else {
            $always_assigned_before_loop_body_vars = Context::get_new_or_updated_var_ids($loop_parent_context, $loop_context);
        }
        $final_actions = Scope_Analyzer::get_control_actions($stmts, $statements_analyzer->node_data, []);
        $does_always_break = $final_actions === [Scope_Analyzer::ACTION_BREAK];
        if ($assignment_map) {
            $first_var_id = array_keys($assignment_map)[0];
            $assignment_depth = self::get_assignment_map_depth($first_var_id, $assignment_map);
        }
        if ($assignment_depth === 0 || $does_always_break) {
            $continue_context = clone $loop_context;
            $continue_context->loop_scope = $loop_scope;
            foreach ($pre_conditions as $condition_offset => $pre_condition) {
                self::apply_pre_condition_to_loop_context($statements_analyzer, $pre_condition, $pre_condition_clauses[$condition_offset], $continue_context, $loop_parent_context, $is_do);
            }
            $continue_context->protected_var_ids = $loop_scope->protected_var_ids;
            $statements_analyzer->analyze($stmts, $continue_context);
            self::update_loop_scope_contexts($loop_scope, $loop_context, $continue_context, $loop_parent_context);
            foreach ($post_expressions as $post_expression) {
                if (Expression_Analyzer::analyze($statements_analyzer, $post_expression, $loop_context) === false) {
                    return false;
                }
            }
            $loop_parent_context->vars_possibly_in_scope += $continue_context->vars_possibly_in_scope;
        } else {
            $original_parent_context = clone $loop_parent_context;
            $analyzer = $statements_analyzer->get_codebase()->analyzer;
            $original_mixed_counts = $analyzer->get_mixed_counts_for_file($statements_analyzer->get_file_path());
            // record all the vars that existed before we did the first pass through the loop
            $pre_loop_context = clone $loop_context;
            Issue_Buffer::start_recording();
            if (!$is_do) {
                foreach ($pre_conditions as $condition_offset => $pre_condition) {
                    self::apply_pre_condition_to_loop_context($statements_analyzer, $pre_condition, $pre_condition_clauses[$condition_offset], $loop_context, $loop_parent_context, $is_do);
                }
            }
            $continue_context = clone $loop_context;
            $continue_context->loop_scope = $loop_scope;
            $continue_context->protected_var_ids = $loop_scope->protected_var_ids;
            $statements_analyzer->analyze($stmts, $continue_context);
            self::update_loop_scope_contexts($loop_scope, $loop_context, $continue_context, $original_parent_context);
            $continue_context->protected_var_ids = $original_protected_var_ids;
            if ($is_do) {
                $inner_do_context = clone $continue_context;
                foreach ($pre_conditions as $condition_offset => $pre_condition) {
                    $always_assigned_before_loop_body_vars = [...self::apply_pre_condition_to_loop_context($statements_analyzer, $pre_condition, $pre_condition_clauses[$condition_offset], $continue_context, $loop_parent_context, $is_do), ...$always_assigned_before_loop_body_vars];
                }
            }
            $always_assigned_before_loop_body_vars = array_unique($always_assigned_before_loop_body_vars);
            foreach ($post_expressions as $post_expression) {
                if (Expression_Analyzer::analyze($statements_analyzer, $post_expression, $continue_context) === false) {
                    return false;
                }
            }
            $recorded_issues = Issue_Buffer::clear_recording_level();
            Issue_Buffer::stop_recording();
            for ($i = 0; $i < $assignment_depth; ++$i) {
                $vars_to_remove = [];
                $loop_scope->iteration_count++;
                $has_changes = false;
                // reset the $continue_context to what it was before we started the analysis,
                // but union the types with what's in the loop scope
                foreach ($continue_context->vars_in_scope as $var_id => $type) {
                    if (in_array($var_id, $always_assigned_before_loop_body_vars, true)) {
                        // set the vars to whatever the while/foreach loop expects them to be
                        if (!isset($pre_loop_context->vars_in_scope[$var_id]) || !$type->equals($pre_loop_context->vars_in_scope[$var_id])) {
                            $has_changes = true;
                        }
                    } elseif (isset($original_parent_context->vars_in_scope[$var_id])) {
                        if (!$type->equals($original_parent_context->vars_in_scope[$var_id])) {
                            $has_changes = true;
                            // widen the foreach context type with the initial context type
                            $continue_context->vars_in_scope[$var_id] = Type::combine_union_types($continue_context->vars_in_scope[$var_id], $original_parent_context->vars_in_scope[$var_id]);
                            // if there's a change, invalidate related clauses
                            $pre_loop_context->remove_var_from_conflicting_clauses($var_id);
                            $loop_parent_context->possibly_assigned_var_ids[$var_id] = true;
                        }
                        if (isset($loop_context->vars_in_scope[$var_id]) && !$type->equals($loop_context->vars_in_scope[$var_id])) {
                            $has_changes = true;
                            // widen the foreach context type with the initial context type
                            $continue_context->vars_in_scope[$var_id] = Type::combine_union_types($continue_context->vars_in_scope[$var_id], $loop_context->vars_in_scope[$var_id]);
                            // if there's a change, invalidate related clauses
                            $pre_loop_context->remove_var_from_conflicting_clauses($var_id);
                        }
                    } else {
                        // give an opportunity to redeemed UndefinedVariable issues
                        if ($recorded_issues) {
                            $has_changes = true;
                        }
                        // if we're in a do block we don't want to remove vars before evaluating
                        // the where conditional
                        if (!$is_do) {
                            $vars_to_remove[] = $var_id;
                        }
                    }
                }
                $continue_context->has_returned = false;
                $loop_parent_context->vars_possibly_in_scope += $continue_context->vars_possibly_in_scope;
                // if there are no changes to the types, no need to re-examine
                if (!$has_changes) {
                    break;
                }
                // remove vars that were defined in the foreach
                foreach ($vars_to_remove as $var_id) {
                    $continue_context->remove_possible_reference($var_id);
                }
                $continue_context->clauses = $pre_loop_context->clauses;
                $continue_context->byref_constraints = $pre_loop_context->byref_constraints;
                $analyzer->set_mixed_counts_for_file($statements_analyzer->get_file_path(), $original_mixed_counts);
                Issue_Buffer::start_recording();
                if (!$is_do) {
                    foreach ($pre_conditions as $condition_offset => $pre_condition) {
                        self::apply_pre_condition_to_loop_context($statements_analyzer, $pre_condition, $pre_condition_clauses[$condition_offset], $continue_context, $loop_parent_context, false);
                    }
                }
                foreach ($always_assigned_before_loop_body_vars as $var_id) {
                    if (!isset($continue_context->vars_in_scope[$var_id]) || $continue_context->vars_in_scope[$var_id]->get_id() !== $pre_loop_context->vars_in_scope[$var_id]->get_id() || $continue_context->vars_in_scope[$var_id]->from_docblock !== $pre_loop_context->vars_in_scope[$var_id]->from_docblock) {
                        if (isset($pre_loop_context->vars_in_scope[$var_id])) {
                            $continue_context->vars_in_scope[$var_id] = $pre_loop_context->vars_in_scope[$var_id];
                        } else {
                            $continue_context->remove_possible_reference($var_id);
                        }
                    }
                }
                $continue_context->clauses = $pre_loop_context->clauses;
                $continue_context->protected_var_ids = $loop_scope->protected_var_ids;
                $traverser = new Php_Parser\Node_Traverser();
                $traverser->add_visitor(new Node_Cleaner_Visitor($statements_analyzer->node_data));
                $traverser->traverse($stmts);
                $statements_analyzer->analyze($stmts, $continue_context);
                self::update_loop_scope_contexts($loop_scope, $loop_context, $continue_context, $original_parent_context);
                $continue_context->protected_var_ids = $original_protected_var_ids;
                if ($is_do) {
                    $inner_do_context = clone $continue_context;
                    foreach ($pre_conditions as $condition_offset => $pre_condition) {
                        self::apply_pre_condition_to_loop_context($statements_analyzer, $pre_condition, $pre_condition_clauses[$condition_offset], $continue_context, $loop_parent_context, $is_do);
                    }
                }
                foreach ($post_expressions as $post_expression) {
                    if (Expression_Analyzer::analyze($statements_analyzer, $post_expression, $continue_context) === false) {
                        return false;
                    }
                }
                $recorded_issues = Issue_Buffer::clear_recording_level();
                Issue_Buffer::stop_recording();
            }
            foreach ($recorded_issues as $recorded_issue) {
                // if we're not in any loops then this will just result in the issue being emitted
                Issue_Buffer::bubble_up($recorded_issue);
            }
        }
        $does_sometimes_break = in_array(Scope_Analyzer::ACTION_BREAK, $loop_scope->final_actions, true);
        $does_always_break = $loop_scope->final_actions === [Scope_Analyzer::ACTION_BREAK];
        if ($does_sometimes_break) {
            foreach ($loop_scope->possibly_redefined_loop_parent_vars as $var => $type) {
                $loop_parent_context->vars_in_scope[$var] = Type::combine_union_types($type, $loop_parent_context->vars_in_scope[$var]);
                $loop_parent_context->possibly_assigned_var_ids[$var] = true;
            }
        }
        foreach ($loop_parent_context->vars_in_scope as $var_id => $type) {
            if (!isset($loop_context->vars_in_scope[$var_id])) {
                continue;
            }
            if ($loop_context->vars_in_scope[$var_id]->get_id() !== $type->get_id()) {
                $loop_parent_context->vars_in_scope[$var_id] = Type::combine_union_types($loop_parent_context->vars_in_scope[$var_id], $loop_context->vars_in_scope[$var_id]);
                $loop_parent_context->remove_var_from_conflicting_clauses($var_id);
            } else {
                $loop_parent_context->vars_in_scope[$var_id] = $loop_parent_context->vars_in_scope[$var_id]->add_parent_nodes($loop_context->vars_in_scope[$var_id]->parent_nodes);
            }
        }
        if (!$does_always_break) {
            foreach ($loop_parent_context->vars_in_scope as $var_id => $type) {
                if (!isset($continue_context->vars_in_scope[$var_id])) {
                    $loop_parent_context->remove_possible_reference($var_id);
                    continue;
                }
                if ($continue_context->vars_in_scope[$var_id]->has_mixed()) {
                    $continue_context->vars_in_scope[$var_id] = $continue_context->vars_in_scope[$var_id]->add_parent_nodes($loop_parent_context->vars_in_scope[$var_id]->parent_nodes);
                    $loop_parent_context->vars_in_scope[$var_id] = $continue_context->vars_in_scope[$var_id];
                    $loop_parent_context->remove_var_from_conflicting_clauses($var_id);
                    continue;
                }
                if ($continue_context->vars_in_scope[$var_id]->get_id() !== $type->get_id()) {
                    $loop_parent_context->vars_in_scope[$var_id] = Type::combine_union_types($loop_parent_context->vars_in_scope[$var_id], $continue_context->vars_in_scope[$var_id]);
                    $loop_parent_context->remove_var_from_conflicting_clauses($var_id);
                } else {
                    $loop_parent_context->vars_in_scope[$var_id] = $loop_parent_context->vars_in_scope[$var_id]->set_parent_nodes([...$loop_parent_context->vars_in_scope[$var_id]->parent_nodes, ...$continue_context->vars_in_scope[$var_id]->parent_nodes]);
                }
            }
        }
        if ($pre_conditions && $pre_condition_clauses && !$does_sometimes_break) {
            // if the loop contains an assertion and there are no break statements, we can negate that assertion
            // and apply it to the current context
            try {
                $negated_pre_condition_clauses = Algebra::negate_formula(array_merge(...$pre_condition_clauses));
            } catch (Complicated_Expression_Exception) {
                $negated_pre_condition_clauses = [];
            }
            $negated_pre_condition_types = Algebra::get_truths_from_formula($negated_pre_condition_clauses);
            if ($negated_pre_condition_types) {
                $changed_var_ids = [];
                [$vars_in_scope_reconciled, $_] = Reconciler::reconcile_keyed_types($negated_pre_condition_types, [], $continue_context->vars_in_scope, $continue_context->references_in_scope, $changed_var_ids, [], $statements_analyzer, [], true, new Code_Location($statements_analyzer->get_source(), $pre_conditions[0]));
                foreach ($changed_var_ids as $var_id => $_) {
                    if (isset($vars_in_scope_reconciled[$var_id]) && isset($loop_parent_context->vars_in_scope[$var_id])) {
                        $loop_parent_context->vars_in_scope[$var_id] = $vars_in_scope_reconciled[$var_id];
                    }
                    $loop_parent_context->remove_var_from_conflicting_clauses($var_id);
                }
            }
        }
        if ($always_enters_loop) {
            self::set_loop_vars($continue_context, $loop_parent_context, $loop_scope);
        }
        if ($inner_do_context) {
            $continue_context = $inner_do_context;
        }
        // Track references set in the loop to make sure they aren't reused later
        $loop_parent_context->update_references_possibly_from_confusing_scope($continue_context, $statements_analyzer);
        return null;
    }
    /**
     * @param array<string, Union> $init_var_types
     * @param array<string, int> $assigned_var_ids
     * @param list<PhpParser\Node\Expr> $pre_conditions
     * @param PhpParser\Node\Expr[] $post_expressions
     * @return false|null
     */
    public static function analyze_for_or_while(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Stmt\For_|Php_Parser\Node\Stmt\While_ $stmt, Context $context, bool $while_true, array $init_var_types, array $assigned_var_ids, array $pre_conditions, array $post_expressions): ?bool
    {
        $pre_context = null;
        if ($while_true) {
            $pre_context = clone $context;
        }
        $for_context = clone $context;
        $for_context->inside_loop = true;
        $for_context->break_types[] = 'loop';
        $codebase = $statements_analyzer->get_codebase();
        if ($codebase->alter_code && $for_context->branch_point === null) {
            $for_context->branch_point = (int) $stmt->get_attribute('startFilePos');
        }
        $loop_scope = new Loop_Scope($for_context, $context);
        $loop_scope->protected_var_ids = array_merge($assigned_var_ids, $context->protected_var_ids);
        if (self::analyze($statements_analyzer, $stmt->stmts, $pre_conditions, $post_expressions, $loop_scope, $inner_loop_context) === false) {
            return false;
        }
        if (!$inner_loop_context) {
            throw new UnexpectedValueException('There should be an inner loop context');
        }
        $always_enters_loop = $while_true || self::does_enter_loop($statements_analyzer, $stmt, $init_var_types);
        $can_leave_loop = !$while_true || in_array(Scope_Analyzer::ACTION_BREAK, $loop_scope->final_actions, true);
        if ($always_enters_loop && $can_leave_loop) {
            self::set_loop_vars($inner_loop_context, $context, $loop_scope);
        }
        $for_context->loop_scope = null;
        if ($can_leave_loop) {
            $context->vars_possibly_in_scope = [...$context->vars_possibly_in_scope, ...$for_context->vars_possibly_in_scope];
        } elseif ($pre_context) {
            $context->vars_possibly_in_scope = $pre_context->vars_possibly_in_scope;
        }
        if ($context->collect_exceptions) {
            $context->merge_exceptions($for_context);
        }
        return null;
    }
    public static function set_loop_vars(Context $inner_context, Context $context, Loop_Scope $loop_scope): void
    {
        foreach ($inner_context->vars_in_scope as $var_id => $type) {
            // if there are break statements in the loop it's not certain
            // that the loop has finished executing, so the assertions at the end
            // the loop in the while conditional may not hold
            if (in_array(Scope_Analyzer::ACTION_BREAK, $loop_scope->final_actions, true) || in_array(Scope_Analyzer::ACTION_CONTINUE, $loop_scope->final_actions, true)) {
                if (isset($loop_scope->possibly_defined_loop_parent_vars[$var_id])) {
                    $context->vars_in_scope[$var_id] = Type::combine_union_types($type, $loop_scope->possibly_defined_loop_parent_vars[$var_id]);
                }
            } else {
                $context->vars_in_scope[$var_id] = $type;
            }
        }
    }
    /**
     * @param array<string, Union> $init_var_types
     */
    private static function does_enter_loop(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Stmt\For_|Php_Parser\Node\Stmt\While_ $stmt, array $init_var_types): bool
    {
        $always_enters_loop = false;
        if ($stmt instanceof Php_Parser\Node\Stmt\While_) {
            if ($cond_type = $statements_analyzer->node_data->get_type($stmt->cond)) {
                $always_enters_loop = $cond_type->is_always_truthy();
            }
        } else {
            foreach ($stmt->cond as $cond) {
                if ($cond_type = $statements_analyzer->node_data->get_type($cond)) {
                    $always_enters_loop = $cond_type->is_always_truthy();
                }
                if (count($stmt->init) === 1 && count($stmt->cond) === 1 && $cond instanceof Php_Parser\Node\Expr\Binary_Op && ($cond_value = $statements_analyzer->node_data->get_type($cond->right)) && ($cond_value->is_single_int_literal() || $cond_value->is_single_string_literal()) && $cond->left instanceof Php_Parser\Node\Expr\Variable && is_string($cond->left->name) && isset($init_var_types[$cond->left->name]) && $init_var_types[$cond->left->name]->is_single_int_literal()) {
                    $init_value = $init_var_types[$cond->left->name]->get_single_literal()->value;
                    $cond_value = $cond_value->get_single_literal()->value;
                    if ($cond instanceof Php_Parser\Node\Expr\Binary_Op\Smaller && $init_value < $cond_value) {
                        $always_enters_loop = true;
                        break;
                    }
                    if ($cond instanceof Php_Parser\Node\Expr\Binary_Op\Smaller_Or_Equal && $init_value <= $cond_value) {
                        $always_enters_loop = true;
                        break;
                    }
                    if ($cond instanceof Php_Parser\Node\Expr\Binary_Op\Greater && $init_value > $cond_value) {
                        $always_enters_loop = true;
                        break;
                    }
                    if ($cond instanceof Php_Parser\Node\Expr\Binary_Op\Greater_Or_Equal && $init_value >= $cond_value) {
                        $always_enters_loop = true;
                        break;
                    }
                }
            }
        }
        return $always_enters_loop;
    }
    private static function update_loop_scope_contexts(Loop_Scope $loop_scope, Context $loop_context, Context $continue_context, Context $pre_outer_context): void
    {
        if (!in_array(Scope_Analyzer::ACTION_CONTINUE, $loop_scope->final_actions, true)) {
            $loop_context->vars_in_scope = $pre_outer_context->vars_in_scope;
        } else {
            $updated_loop_vars = [];
            foreach ($loop_scope->redefined_loop_vars as $var => $type) {
                $continue_context->vars_in_scope[$var] = $type;
                $updated_loop_vars[$var] = true;
            }
            foreach ($loop_scope->possibly_redefined_loop_vars as $var => $type) {
                if ($continue_context->has_variable($var)) {
                    if (!isset($updated_loop_vars[$var])) {
                        $continue_context->vars_in_scope[$var] = Type::combine_union_types($continue_context->vars_in_scope[$var], $type);
                    } else {
                        $continue_context->vars_in_scope[$var] = $continue_context->vars_in_scope[$var]->add_parent_nodes($type->parent_nodes);
                    }
                }
            }
        }
        // merge vars possibly in scope at the end of each loop
        $loop_context->vars_possibly_in_scope = [...$loop_context->vars_possibly_in_scope, ...$loop_scope->vars_possibly_in_scope];
    }
    /**
     * @param  list<Clause>  $pre_condition_clauses
     * @return list<string>
     */
    private static function apply_pre_condition_to_loop_context(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr $pre_condition, array $pre_condition_clauses, Context $loop_context, Context $outer_context, bool $is_do): array
    {
        $pre_referenced_var_ids = $loop_context->cond_referenced_var_ids;
        $loop_context->cond_referenced_var_ids = [];
        $was_inside_conditional = $loop_context->inside_conditional;
        $loop_context->inside_conditional = true;
        if (Expression_Analyzer::analyze($statements_analyzer, $pre_condition, $loop_context) === false) {
            $loop_context->inside_conditional = $was_inside_conditional;
            return [];
        }
        $loop_context->inside_conditional = $was_inside_conditional;
        $new_referenced_var_ids = $loop_context->cond_referenced_var_ids;
        $loop_context->cond_referenced_var_ids = array_merge($pre_referenced_var_ids, $new_referenced_var_ids);
        $always_assigned_before_loop_body_vars = Context::get_new_or_updated_var_ids($outer_context, $loop_context);
        $loop_context->clauses = Algebra::simplify_cnf([...$outer_context->clauses, ...$pre_condition_clauses]);
        $active_while_types = [];
        $reconcilable_while_types = Algebra::get_truths_from_formula($loop_context->clauses, spl_object_id($pre_condition), $new_referenced_var_ids, $active_while_types);
        $changed_var_ids = [];
        if ($reconcilable_while_types) {
            [$loop_context->vars_in_scope, $loop_context->references_in_scope] = Reconciler::reconcile_keyed_types($reconcilable_while_types, $active_while_types, $loop_context->vars_in_scope, $loop_context->references_in_scope, $changed_var_ids, $new_referenced_var_ids, $statements_analyzer, [], true, new Code_Location($statements_analyzer->get_source(), $pre_condition));
        }
        if ($is_do) {
            return [];
        }
        foreach ($always_assigned_before_loop_body_vars as $var_id) {
            $loop_context->clauses = Context::filter_clauses($var_id, $loop_context->clauses, null, $statements_analyzer);
        }
        return $always_assigned_before_loop_body_vars;
    }
    /**
     * @param  array<string, array<string, bool>>   $assignment_map
     */
    private static function get_assignment_map_depth(string $first_var_id, array $assignment_map): int
    {
        $max_depth = 0;
        $assignment_var_ids = $assignment_map[$first_var_id];
        unset($assignment_map[$first_var_id]);
        foreach ($assignment_var_ids as $assignment_var_id => $_) {
            $depth = 1;
            if (isset($assignment_map[$assignment_var_id])) {
                $depth = 1 + self::get_assignment_map_depth($assignment_var_id, $assignment_map);
            }
            if ($depth > $max_depth) {
                $max_depth = $depth;
            }
        }
        return $max_depth;
    }
}