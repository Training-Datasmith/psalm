<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression;

use Php_Parser;
use Php_Parser\Comment\Doc;
use Php_Parser\Node\Expr;
use Php_Parser\Node\Expr\Array_Dim_Fetch;
use Php_Parser\Node\Expr\Property_Fetch;
use Psalm\Code_Location;
use Psalm\Code_Location\Docblock_Type_Location;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Exception\Docblock_Parse_Exception;
use Psalm\Exception\Incorrect_Docblock_Exception;
use Psalm\Internal\Algebra;
use Psalm\Internal\Algebra\Formula_Generator;
use Psalm\Internal\Analyzer\Comment_Analyzer;
use Psalm\Internal\Analyzer\Function_Like_Analyzer;
use Psalm\Internal\Analyzer\Statements\Block\Foreach_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Assignment\Array_Assignment_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Assignment\Instance_Property_Assignment_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Assignment\Static_Property_Assignment_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Fetch\Array_Fetch_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Fetch\Variable_Fetch_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Analyzer\Trait_Analyzer;
use Psalm\Internal\Clause;
use Psalm\Internal\Codebase\Data_Flow_Graph;
use Psalm\Internal\Codebase\Taint_Flow_Graph;
use Psalm\Internal\Codebase\Variable_Use_Graph;
use Psalm\Internal\Data_Flow\Data_Flow_Node;
use Psalm\Internal\Data_Flow\Taint_Source;
use Psalm\Internal\File_Manipulation\File_Manipulation_Buffer;
use Psalm\Internal\Reference_Constraint;
use Psalm\Internal\Scanner\Var_Docblock_Comment;
use Psalm\Internal\Type\Comparator\Union_Type_Comparator;
use Psalm\Internal\Type\Type_Expander;
use Psalm\Issue\Assignment_To_Void;
use Psalm\Issue\Impure_By_Reference_Assignment;
use Psalm\Issue\Impure_Property_Assignment;
use Psalm\Issue\Invalid_Array_Access;
use Psalm\Issue\Invalid_Array_Offset;
use Psalm\Issue\Invalid_Docblock;
use Psalm\Issue\Invalid_Scope;
use Psalm\Issue\Loop_Invalidation;
use Psalm\Issue\Missing_Docblock_Type;
use Psalm\Issue\Mixed_Array_Access;
use Psalm\Issue\Mixed_Assignment;
use Psalm\Issue\No_Value;
use Psalm\Issue\Null_Reference;
use Psalm\Issue\Possibly_Invalid_Array_Access;
use Psalm\Issue\Possibly_Null_Array_Access;
use Psalm\Issue\Possibly_Undefined_Array_Offset;
use Psalm\Issue\Possibly_Undefined_Int_Array_Offset;
use Psalm\Issue\Reference_Constraint_Violation;
use Psalm\Issue\Reference_Reused_From_Confusing_Scope;
use Psalm\Issue\Unnecessary_Var_Annotation;
use Psalm\Issue\Unsupported_Property_Reference_Usage;
use Psalm\Issue_Buffer;
use Psalm\Node\Expr\Binary_Op\Virtual_Bitwise_And;
use Psalm\Node\Expr\Binary_Op\Virtual_Bitwise_Or;
use Psalm\Node\Expr\Binary_Op\Virtual_Bitwise_Xor;
use Psalm\Node\Expr\Binary_Op\Virtual_Coalesce;
use Psalm\Node\Expr\Binary_Op\Virtual_Concat;
use Psalm\Node\Expr\Binary_Op\Virtual_Div;
use Psalm\Node\Expr\Binary_Op\Virtual_Minus;
use Psalm\Node\Expr\Binary_Op\Virtual_Mod;
use Psalm\Node\Expr\Binary_Op\Virtual_Mul;
use Psalm\Node\Expr\Binary_Op\Virtual_Plus;
use Psalm\Node\Expr\Binary_Op\Virtual_Pow;
use Psalm\Node\Expr\Binary_Op\Virtual_Shift_Left;
use Psalm\Node\Expr\Binary_Op\Virtual_Shift_Right;
use Psalm\Node\Expr\Virtual_Assign;
use Psalm\Plugin\Event_Handler\Event\Add_Remove_Taints_Event;
use Psalm\Storage\Assertion\Falsy;
use Psalm\Type;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_False;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Atomic\T_Mixed;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Non_Empty_Array;
use Psalm\Type\Atomic\T_Null;
use Psalm\Type\Union;
use UnexpectedValueException;
use function array_diff;
use function count;
use function in_array;
use function is_string;
use function reset;
use function spl_object_id;
use function str_contains;
use function str_starts_with;
use function strpos;
use function strtolower;
/**
 * @internal
 */
final class Assignment_Analyzer
{
    /**
     * @param  PhpParser\Node\Expr|null $assign_value  This has to be null to support list destructuring
     */
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr $assign_var, ?Php_Parser\Node\Expr $assign_value, ?Union $assign_value_type, Context $context, ?Php_Parser\Comment\Doc $doc_comment, array $not_ignored_docblock_var_ids = [], ?Php_Parser\Node\Expr $assign_expr = null): ?Union
    {
        $var_id = Expression_Identifier::get_var_id($assign_var, $statements_analyzer->get_fqcln(), $statements_analyzer);
        // gets a variable id that *may* contain array keys
        $extended_var_id = Expression_Identifier::get_extended_var_id($assign_var, $statements_analyzer->get_fqcln(), $statements_analyzer);
        $var_comments = [];
        $comment_type = null;
        $comment_type_location = null;
        $was_in_assignment = $context->inside_assignment;
        $context->inside_assignment = true;
        $codebase = $statements_analyzer->get_codebase();
        $base_assign_value = $assign_value;
        while ($base_assign_value instanceof Php_Parser\Node\Expr\Assign) {
            $base_assign_value = $base_assign_value->expr;
        }
        if ($base_assign_value !== $assign_value) {
            Expression_Analyzer::analyze($statements_analyzer, $base_assign_value, $context);
            $assign_value_type = $statements_analyzer->node_data->get_type($base_assign_value) ?? $assign_value_type;
        }
        $removed_taints = [];
        self::analyze_doc_comment($statements_analyzer, $codebase, $context, $assign_var, $var_id, $assign_value_type, $doc_comment, $var_comments, $comment_type, $comment_type_location, $not_ignored_docblock_var_ids, $removed_taints);
        if ($extended_var_id) {
            unset($context->cond_referenced_var_ids[$extended_var_id]);
            $context->assigned_var_ids[$extended_var_id] = (int) $assign_var->get_attribute('startFilePos');
            $context->possibly_assigned_var_ids[$extended_var_id] = true;
        }
        if ($assign_value) {
            if ($var_id && $assign_value instanceof Php_Parser\Node\Expr\Closure) {
                foreach ($assign_value->uses as $closure_use) {
                    if ($closure_use->by_ref && is_string($closure_use->var->name) && $var_id === '$' . $closure_use->var->name) {
                        $context->vars_in_scope[$var_id] = Type::get_closure();
                        $context->vars_possibly_in_scope[$var_id] = true;
                    }
                }
            }
            $was_inside_general_use = $context->inside_general_use;
            $root_expr = $assign_var;
            while ($root_expr instanceof Php_Parser\Node\Expr\Array_Dim_Fetch) {
                $root_expr = $root_expr->var;
            }
            // if we don't know where this data is going, treat as a dead-end usage
            if (!$root_expr instanceof Php_Parser\Node\Expr\Variable || is_string($root_expr->name) && in_array('$' . $root_expr->name, Variable_Fetch_Analyzer::SUPER_GLOBALS, true)) {
                $context->inside_general_use = true;
            }
            if (Expression_Analyzer::analyze($statements_analyzer, $assign_value, $context) === false) {
                $context->inside_general_use = $was_inside_general_use;
                if ($var_id) {
                    if ($extended_var_id && isset($context->vars_in_scope[$extended_var_id])) {
                        $context->remove_descendents($extended_var_id, $context->vars_in_scope[$extended_var_id], $assign_value_type);
                    }
                    // if we're not exiting immediately, make everything mixed
                    $context->vars_in_scope[$var_id] = $comment_type ?? Type::get_mixed();
                }
                return null;
            }
            $context->inside_general_use = $was_inside_general_use;
        }
        if ($comment_type && $comment_type_location) {
            $temp_assign_value_type = $assign_value_type ?? ($assign_value ? $statements_analyzer->node_data->get_type($assign_value) : null);
            if ($codebase->find_unused_variables && $temp_assign_value_type && $extended_var_id && (!$not_ignored_docblock_var_ids || isset($not_ignored_docblock_var_ids[$extended_var_id])) && $temp_assign_value_type->get_id() === $comment_type->get_id() && !$comment_type->is_mixed(true)) {
                if ($codebase->alter_code && isset($statements_analyzer->get_project_analyzer()->get_issues_to_fix()['UnnecessaryVarAnnotation'])) {
                    File_Manipulation_Buffer::add_var_annotation_to_remove($comment_type_location);
                } else {
                    Issue_Buffer::maybe_add(new Unnecessary_Var_Annotation('The @var ' . $comment_type . ' annotation for ' . $extended_var_id . ' is unnecessary', $comment_type_location), $statements_analyzer->get_suppressed_issues(), true);
                }
            }
            $parent_nodes = $temp_assign_value_type->parent_nodes ?? [];
            $assign_value_type = $comment_type->set_parent_nodes($parent_nodes);
        } elseif (!$assign_value_type) {
            if ($assign_value) {
                $assign_value_type = $statements_analyzer->node_data->get_type($assign_value);
            }
            if ($assign_value_type) {
                $assign_value_type = $assign_value_type->set_properties(['from_property' => false, 'from_static_property' => false, 'ignore_isset' => false]);
            } else {
                $assign_value_type = Type::get_mixed();
            }
        }
        if ($statements_analyzer->data_flow_graph instanceof Variable_Use_Graph && !$assign_value_type->parent_nodes) {
            $assign_value_type = self::analyze_variable_use($statements_analyzer, $assign_var, $extended_var_id, $assign_value_type, $context);
        }
        if ($extended_var_id && isset($context->vars_in_scope[$extended_var_id])) {
            if ($context->vars_in_scope[$extended_var_id]->by_ref) {
                if ($context->mutation_free) {
                    Issue_Buffer::maybe_add(new Impure_By_Reference_Assignment('Variable ' . $extended_var_id . ' cannot be assigned to as it is passed by reference', new Code_Location($statements_analyzer->get_source(), $assign_var)));
                } elseif ($statements_analyzer->get_source() instanceof Function_Like_Analyzer && $statements_analyzer->get_source()->track_mutations) {
                    $statements_analyzer->get_source()->inferred_impure = true;
                    $statements_analyzer->get_source()->inferred_has_mutation = true;
                }
                $assign_value_type = $assign_value_type->set_by_ref(true);
            }
            // removes dependent vars from $context
            $context->remove_descendents($extended_var_id, $context->vars_in_scope[$extended_var_id], $assign_value_type, $statements_analyzer);
        } else {
            $root_var_id = Expression_Identifier::get_root_var_id($assign_var, $statements_analyzer->get_fqcln(), $statements_analyzer);
            if ($root_var_id && isset($context->vars_in_scope[$root_var_id])) {
                $context->remove_var_from_conflicting_clauses($root_var_id, $context->vars_in_scope[$root_var_id], $statements_analyzer);
            }
        }
        if ($assign_value_type->has_mixed()) {
            $root_var_id = Expression_Identifier::get_root_var_id($assign_var, $statements_analyzer->get_fqcln(), $statements_analyzer);
            if (!$context->collect_initializations && !$context->collect_mutations && $statements_analyzer->get_file_path() === $statements_analyzer->get_root_file_path() && (!($parent_source = $statements_analyzer->get_source()) instanceof Function_Like_Analyzer || !$parent_source->get_source() instanceof Trait_Analyzer)) {
                $codebase->analyzer->increment_mixed_count($statements_analyzer->get_file_path());
            }
            if (!$assign_var instanceof Php_Parser\Node\Expr\Property_Fetch && !strpos($root_var_id ?? '', '->') && !$comment_type && !str_starts_with($var_id ?? '', '$_')) {
                $origin_locations = [];
                if ($statements_analyzer->data_flow_graph instanceof Variable_Use_Graph) {
                    foreach ($assign_value_type->parent_nodes as $parent_node) {
                        $origin_locations = [...$origin_locations, ...$statements_analyzer->data_flow_graph->get_origin_locations($parent_node)];
                    }
                }
                $origin_location = count($origin_locations) === 1 ? reset($origin_locations) : null;
                $message = $var_id ? 'Unable to determine the type that ' . $var_id . ' is being assigned to' : 'Unable to determine the type of this assignment';
                $issue_location = new Code_Location($statements_analyzer->get_source(), $assign_var);
                if ($origin_location && $origin_location->get_hash() === $issue_location->get_hash()) {
                    $origin_location = null;
                }
                Issue_Buffer::maybe_add(new Mixed_Assignment($message, $issue_location, $origin_location), $statements_analyzer->get_suppressed_issues());
            }
        } else {
            if (!$context->collect_initializations && !$context->collect_mutations && $statements_analyzer->get_file_path() === $statements_analyzer->get_root_file_path() && (!($parent_source = $statements_analyzer->get_source()) instanceof Function_Like_Analyzer || !$parent_source->get_source() instanceof Trait_Analyzer)) {
                $codebase->analyzer->increment_non_mixed_count($statements_analyzer->get_file_path());
            }
            if ($var_id && isset($context->byref_constraints[$var_id]) && $outer_constraint_type = $context->byref_constraints[$var_id]->type) {
                if (!Union_Type_Comparator::is_contained_by($codebase, $assign_value_type, $outer_constraint_type, $assign_value_type->ignore_nullable_issues, $assign_value_type->ignore_falsable_issues)) {
                    Issue_Buffer::maybe_add(new Reference_Constraint_Violation('Variable ' . $var_id . ' is limited to values of type ' . $context->byref_constraints[$var_id]->type . ' because it is passed by reference, ' . $assign_value_type->get_id() . ' type found', new Code_Location($statements_analyzer->get_source(), $assign_var)), $statements_analyzer->get_suppressed_issues());
                }
            }
        }
        if ($var_id === '$this' && Issue_Buffer::accepts(new Invalid_Scope('Cannot re-assign ' . $var_id, new Code_Location($statements_analyzer->get_source(), $assign_var)), $statements_analyzer->get_suppressed_issues())) {
            return null;
        }
        if ($var_id !== null && isset($context->protected_var_ids[$var_id]) && $assign_value_type->has_literal_int()) {
            Issue_Buffer::maybe_add(new Loop_Invalidation('Variable ' . $var_id . ' has already been assigned in a for/foreach loop', new Code_Location($statements_analyzer->get_source(), $assign_var)), $statements_analyzer->get_suppressed_issues());
        }
        if (self::analyze_assignment($assign_var, $statements_analyzer, $codebase, $assign_value, $assign_value_type, $var_id, $context, $doc_comment, $extended_var_id, $var_comments, $removed_taints) === false) {
            return null;
        }
        if ($var_id && isset($context->vars_in_scope[$var_id])) {
            if ($context->vars_in_scope[$var_id]->is_void()) {
                Issue_Buffer::maybe_add(new Assignment_To_Void('Cannot assign ' . $var_id . ' to type void', new Code_Location($statements_analyzer->get_source(), $assign_var)), $statements_analyzer->get_suppressed_issues());
                $context->vars_in_scope[$var_id] = Type::get_null();
                $context->inside_assignment = $was_in_assignment;
                return $context->vars_in_scope[$var_id];
            }
            if ($context->vars_in_scope[$var_id]->is_never()) {
                if (!Issue_Buffer::accepts(new No_Value('All possible types for this assignment were invalidated - This may be dead code', new Code_Location($statements_analyzer->get_source(), $assign_var)), $statements_analyzer->get_suppressed_issues())) {
                    // if the error is suppressed, do not treat it as never anymore
                    $new_mutable = $context->vars_in_scope[$var_id]->get_builder()->add_type(new T_Mixed());
                    $new_mutable->remove_type('never');
                    $context->vars_in_scope[$var_id] = $new_mutable->freeze();
                    $context->has_returned = false;
                } else {
                    $context->inside_assignment = $was_in_assignment;
                    return $context->vars_in_scope[$var_id];
                }
            }
            self::analyze_assign_value_data_flow($statements_analyzer, $codebase, $assign_var, $assign_expr, $assign_value_type, $var_id, $context, $removed_taints);
        }
        $context->inside_assignment = $was_in_assignment;
        return $assign_value_type;
    }
    /**
     * @param list<VarDocblockComment> $var_comments
     * @param list<string> $removed_taints
     * @return null|false
     */
    private static function analyze_assignment(Expr $assign_var, Statements_Analyzer $statements_analyzer, Codebase $codebase, ?Expr $assign_value, Union $assign_value_type, ?string $var_id, Context $context, ?Doc $doc_comment, ?string $extended_var_id, array $var_comments, array $removed_taints): ?bool
    {
        if ($assign_var instanceof Php_Parser\Node\Expr\Variable) {
            self::analyze_assignment_to_variable($statements_analyzer, $codebase, $assign_var, $assign_value, $assign_value_type, $var_id, $context);
        } elseif ($assign_var instanceof Php_Parser\Node\Expr\List_ || $assign_var instanceof Php_Parser\Node\Expr\Array_) {
            self::analyze_destructuring_assignment($statements_analyzer, $codebase, $assign_var, $assign_value, $assign_value_type, $context, $doc_comment, $extended_var_id, $var_comments, $removed_taints);
        } elseif ($assign_var instanceof Php_Parser\Node\Expr\Array_Dim_Fetch) {
            Array_Assignment_Analyzer::analyze($statements_analyzer, $assign_var, $context, $assign_value, $assign_value_type);
        } elseif ($assign_var instanceof Php_Parser\Node\Expr\Property_Fetch) {
            self::analyze_property_assignment($statements_analyzer, $codebase, $assign_var, $context, $assign_value, $assign_value_type, $var_id);
        } elseif ($assign_var instanceof Php_Parser\Node\Expr\Static_Property_Fetch && $assign_var->class instanceof Php_Parser\Node\Name) {
            if (Expression_Analyzer::analyze($statements_analyzer, $assign_var, $context) === false) {
                return false;
            }
            if (Static_Property_Assignment_Analyzer::analyze($statements_analyzer, $assign_var, $assign_value, $assign_value_type, $context) === false) {
                return false;
            }
            if ($var_id) {
                $context->vars_possibly_in_scope[$var_id] = true;
            }
        }
        return null;
    }
    /**
     * @param list<VarDocblockComment> $var_comments
     * @param list<string> $removed_taints
     */
    private static function analyze_doc_comment(Statements_Analyzer $statements_analyzer, Codebase $codebase, Context $context, Php_Parser\Node $assign_var, ?string $var_id, ?Union $assign_value_type, ?Doc $doc_comment, array &$var_comments, ?Union &$comment_type, ?Docblock_Type_Location &$comment_type_location, array $not_ignored_docblock_var_ids, array &$removed_taints): void
    {
        if (!$doc_comment) {
            return;
        }
        $file_path = $statements_analyzer->get_root_file_path();
        $file_storage_provider = $codebase->file_storage_provider;
        $file_storage = $file_storage_provider->get($file_path);
        $template_type_map = $statements_analyzer->get_template_type_map();
        try {
            $var_comments = $codebase->config->disable_var_parsing ? [] : Comment_Analyzer::get_type_from_comment($doc_comment, $statements_analyzer->get_source(), $statements_analyzer->get_aliases(), $template_type_map, $file_storage->type_aliases);
        } catch (Incorrect_Docblock_Exception $e) {
            Issue_Buffer::maybe_add(new Missing_Docblock_Type($e->get_message(), new Code_Location($statements_analyzer->get_source(), $assign_var)));
            return;
        } catch (Docblock_Parse_Exception $e) {
            Issue_Buffer::maybe_add(new Invalid_Docblock($e->get_message(), new Code_Location($statements_analyzer->get_source(), $assign_var)));
            return;
        }
        foreach ($var_comments as $var_comment) {
            if ($var_comment->removed_taints) {
                $removed_taints = $var_comment->removed_taints;
            }
            self::assign_type_from_var_docblock($statements_analyzer, $assign_var, $var_comment, $context, $var_id, $comment_type, $comment_type_location, $not_ignored_docblock_var_ids, $var_id === $var_comment->var_id && $assign_value_type && $comment_type && $assign_value_type->by_ref);
        }
    }
    public static function assign_type_from_var_docblock(Statements_Analyzer $statements_analyzer, Php_Parser\Node $stmt, Var_Docblock_Comment $var_comment, Context $context, ?string $var_id = null, ?Union &$comment_type = null, ?Docblock_Type_Location &$comment_type_location = null, array $not_ignored_docblock_var_ids = [], bool $by_ref = false): void
    {
        if (!$var_comment->type) {
            return;
        }
        $codebase = $statements_analyzer->get_codebase();
        try {
            $var_comment_type = Type_Expander::expand_union($codebase, $var_comment->type, $context->self, $context->self, $statements_analyzer->get_parent_fqcln());
            $var_comment_type = $var_comment_type->set_properties(['from_docblock' => true, 'by_ref' => $by_ref]);
            /** @psalm-suppress UnusedMethodCall This actually has the side effect of generating issues */
            $var_comment_type->check($statements_analyzer, new Code_Location($statements_analyzer->get_source(), $stmt), $statements_analyzer->get_suppressed_issues(), [], false, false, false, $context->calling_method_id);
            $type_location = null;
            if ($var_comment->type_start && $var_comment->type_end && $var_comment->line_number) {
                $type_location = new Docblock_Type_Location($statements_analyzer, $var_comment->type_start, $var_comment->type_end, $var_comment->line_number);
                if ($codebase->alter_code) {
                    $codebase->classlikes->handle_docblock_type_in_migration($codebase, $statements_analyzer, $var_comment_type, $type_location, $context->calling_method_id);
                }
            }
            if (!$var_comment->var_id || $var_comment->var_id === $var_id) {
                $comment_type = $var_comment_type;
                $comment_type_location = $type_location;
                return;
            }
            $project_analyzer = $statements_analyzer->get_project_analyzer();
            if ($codebase->find_unused_variables && $type_location && (!$not_ignored_docblock_var_ids || isset($not_ignored_docblock_var_ids[$var_comment->var_id])) && isset($context->vars_in_scope[$var_comment->var_id]) && $context->vars_in_scope[$var_comment->var_id]->get_id() === $var_comment_type->get_id() && !$var_comment_type->is_mixed()) {
                if ($codebase->alter_code && isset($project_analyzer->get_issues_to_fix()['UnnecessaryVarAnnotation'])) {
                    File_Manipulation_Buffer::add_var_annotation_to_remove($type_location);
                } else {
                    Issue_Buffer::maybe_add(new Unnecessary_Var_Annotation('The @var ' . $var_comment_type . ' annotation for ' . $var_comment->var_id . ' is unnecessary', $type_location), $statements_analyzer->get_suppressed_issues(), true);
                }
            }
            $parent_nodes = $context->vars_in_scope[$var_comment->var_id]->parent_nodes ?? [];
            $var_comment_type = $var_comment_type->set_parent_nodes($parent_nodes);
            $context->vars_in_scope[$var_comment->var_id] = $var_comment_type;
        } catch (UnexpectedValueException $e) {
            Issue_Buffer::maybe_add(new Invalid_Docblock($e->get_message(), new Code_Location($statements_analyzer->get_source(), $stmt)));
        }
    }
    /**
     * @param list<string> $removed_taints
     * @param list<string> $added_taints
     */
    private static function taint_assignment(Union &$type, Data_Flow_Graph $data_flow_graph, string $var_id, Code_Location $var_location, array $removed_taints, array $added_taints): void
    {
        $parent_nodes = $type->parent_nodes;
        $new_parent_node = Data_Flow_Node::get_for_assignment($var_id, $var_location);
        $data_flow_graph->add_node($new_parent_node);
        $new_parent_nodes = [$new_parent_node->id => $new_parent_node];
        // If taints get added (e.g. due to plugin) this assignment needs to
        // become a new taint source
        $taints = array_diff($added_taints, $removed_taints);
        if ($taints !== [] && $data_flow_graph instanceof Taint_Flow_Graph) {
            $taint_source = Taint_Source::from_node($new_parent_node);
            $taint_source->taints = $taints;
            $data_flow_graph->add_source($taint_source);
        }
        foreach ($parent_nodes as $parent_node) {
            $data_flow_graph->add_path($parent_node, $new_parent_node, '=', $added_taints, $removed_taints);
        }
        $type = $type->set_parent_nodes($new_parent_nodes, false);
    }
    public static function analyze_assignment_operation(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Assign_Op $stmt, Context $context): bool
    {
        if ($stmt instanceof Php_Parser\Node\Expr\Assign_Op\Bitwise_And) {
            $operation = new Virtual_Bitwise_And($stmt->var, $stmt->expr, $stmt->get_attributes());
        } elseif ($stmt instanceof Php_Parser\Node\Expr\Assign_Op\Bitwise_Or) {
            $operation = new Virtual_Bitwise_Or($stmt->var, $stmt->expr, $stmt->get_attributes());
        } elseif ($stmt instanceof Php_Parser\Node\Expr\Assign_Op\Bitwise_Xor) {
            $operation = new Virtual_Bitwise_Xor($stmt->var, $stmt->expr, $stmt->get_attributes());
        } elseif ($stmt instanceof Php_Parser\Node\Expr\Assign_Op\Coalesce) {
            $operation = new Virtual_Coalesce($stmt->var, $stmt->expr, $stmt->get_attributes());
        } elseif ($stmt instanceof Php_Parser\Node\Expr\Assign_Op\Concat) {
            $operation = new Virtual_Concat($stmt->var, $stmt->expr, $stmt->get_attributes());
        } elseif ($stmt instanceof Php_Parser\Node\Expr\Assign_Op\Div) {
            $operation = new Virtual_Div($stmt->var, $stmt->expr, $stmt->get_attributes());
        } elseif ($stmt instanceof Php_Parser\Node\Expr\Assign_Op\Minus) {
            $operation = new Virtual_Minus($stmt->var, $stmt->expr, $stmt->get_attributes());
        } elseif ($stmt instanceof Php_Parser\Node\Expr\Assign_Op\Mod) {
            $operation = new Virtual_Mod($stmt->var, $stmt->expr, $stmt->get_attributes());
        } elseif ($stmt instanceof Php_Parser\Node\Expr\Assign_Op\Mul) {
            $operation = new Virtual_Mul($stmt->var, $stmt->expr, $stmt->get_attributes());
        } elseif ($stmt instanceof Php_Parser\Node\Expr\Assign_Op\Plus) {
            $operation = new Virtual_Plus($stmt->var, $stmt->expr, $stmt->get_attributes());
        } elseif ($stmt instanceof Php_Parser\Node\Expr\Assign_Op\Pow) {
            $operation = new Virtual_Pow($stmt->var, $stmt->expr, $stmt->get_attributes());
        } elseif ($stmt instanceof Php_Parser\Node\Expr\Assign_Op\Shift_Left) {
            $operation = new Virtual_Shift_Left($stmt->var, $stmt->expr, $stmt->get_attributes());
        } elseif ($stmt instanceof Php_Parser\Node\Expr\Assign_Op\Shift_Right) {
            $operation = new Virtual_Shift_Right($stmt->var, $stmt->expr, $stmt->get_attributes());
        } else {
            throw new UnexpectedValueException('Unknown assign op');
        }
        $fake_assignment = new Virtual_Assign($stmt->var, $operation, $stmt->get_attributes());
        $old_node_data = $statements_analyzer->node_data;
        $statements_analyzer->node_data = clone $statements_analyzer->node_data;
        if (Expression_Analyzer::analyze($statements_analyzer, $fake_assignment, $context) === false) {
            return false;
        }
        $old_node_data->set_type($stmt, $statements_analyzer->node_data->get_type($operation) ?? Type::get_mixed());
        $statements_analyzer->node_data = $old_node_data;
        return true;
    }
    public static function analyze_assignment_ref(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Assign_Ref $stmt, Context $context, ?Php_Parser\Node\Stmt $from_stmt): bool
    {
        Expression_Analyzer::analyze($statements_analyzer, $stmt->expr, $context, false, null, null, null, true);
        $lhs_var_id = Expression_Identifier::get_extended_var_id($stmt->var, $statements_analyzer->get_fqcln(), $statements_analyzer);
        $rhs_var_id = Expression_Identifier::get_extended_var_id($stmt->expr, $statements_analyzer->get_fqcln(), $statements_analyzer);
        $doc_comment = $stmt->get_doc_comment() ?? $from_stmt?->get_doc_comment();
        if ($doc_comment) {
            try {
                $var_comments = Comment_Analyzer::get_type_from_comment($doc_comment, $statements_analyzer->get_source(), $statements_analyzer->get_aliases());
            } catch (Incorrect_Docblock_Exception $e) {
                Issue_Buffer::maybe_add(new Missing_Docblock_Type($e->get_message(), new Code_Location($statements_analyzer->get_source(), $stmt)));
            } catch (Docblock_Parse_Exception $e) {
                Issue_Buffer::maybe_add(new Invalid_Docblock($e->get_message(), new Code_Location($statements_analyzer->get_source(), $stmt)));
            }
            if (!empty($var_comments) && $var_comments[0]->type !== null && $var_comments[0]->var_id === null) {
                Issue_Buffer::maybe_add(new Invalid_Docblock("Docblock type cannot be used for reference assignment", new Code_Location($statements_analyzer->get_source(), $stmt)));
            }
        }
        if ($lhs_var_id === null || $rhs_var_id === null) {
            return false;
        }
        if (!isset($context->vars_in_scope[$rhs_var_id])) {
            // Sometimes the $rhs_var_id isn't set in $vars_in_scope, for example if it's an unknown array offset.
            $context->vars_in_scope[$rhs_var_id] = $statements_analyzer->node_data->get_type($stmt->expr) ?? Type::get_mixed();
        }
        if (isset($context->references_in_scope[$lhs_var_id])) {
            // Decrement old referenced variable's reference count
            $context->decrement_reference_count($lhs_var_id);
            // Remove old reference parent node so previously referenced variable usage doesn't count as reference usage
            $old_type = $context->vars_in_scope[$lhs_var_id];
            foreach ($old_type->parent_nodes as $old_parent_node_id => $_) {
                if (str_starts_with($old_parent_node_id, "{$lhs_var_id}-")) {
                    unset($old_type->parent_nodes[$old_parent_node_id]);
                }
            }
        }
        // When assigning an existing reference as a reference it removes the
        // old reference, so it's no longer potentially from a confusing scope.
        unset($context->references_possibly_from_confusing_scope[$lhs_var_id]);
        $context->vars_in_scope[$lhs_var_id] =& $context->vars_in_scope[$rhs_var_id];
        $context->has_variable($lhs_var_id);
        $context->references_in_scope[$lhs_var_id] = $rhs_var_id;
        $context->referenced_counts[$rhs_var_id] = ($context->referenced_counts[$rhs_var_id] ?? 0) + 1;
        if (str_contains($rhs_var_id, '[')) {
            // Reference to array item, we always consider array items to be an external scope for references
            // TODO handle differently so it's detected as unused if the array is unused?
            $context->references_to_external_scope[$lhs_var_id] = true;
        }
        if (str_contains($rhs_var_id, '->')) {
            Issue_Buffer::maybe_add(new Unsupported_Property_Reference_Usage(new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
            // Reference to object property, we always consider object properties to be an external scope for references
            // TODO handle differently so it's detected as unused if the object is unused?
            $context->references_to_external_scope[$lhs_var_id] = true;
        }
        if (str_contains($rhs_var_id, '::')) {
            Issue_Buffer::maybe_add(new Unsupported_Property_Reference_Usage(new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
        }
        $lhs_location = new Code_Location($statements_analyzer->get_source(), $stmt->var);
        if (!$stmt->var instanceof Array_Dim_Fetch && !$stmt->var instanceof Property_Fetch) {
            // If left-hand-side is an array offset or object property, usage is too difficult to track,
            // so it's not registered as an unused variable (this mirrors behavior for non-references).
            $statements_analyzer->register_variable_assignment($lhs_var_id, $lhs_location);
        }
        $lhs_node = Data_Flow_Node::get_for_assignment($lhs_var_id, $lhs_location);
        $context->vars_in_scope[$lhs_var_id] = $context->vars_in_scope[$lhs_var_id]->add_parent_nodes([$lhs_node->id => $lhs_node]);
        if ($stmt->var instanceof Array_Dim_Fetch && $stmt->var->dim !== null) {
            // Analyze offset so that variables in the offset get marked as used
            $was_inside_general_use = $context->inside_general_use;
            $context->inside_general_use = true;
            Expression_Analyzer::analyze($statements_analyzer, $stmt->var->dim, $context);
            $context->inside_general_use = $was_inside_general_use;
        }
        return true;
    }
    public static function assign_by_ref_param(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr $stmt, Union $by_ref_type, Union $by_ref_out_type, Context $context, bool $constrain_type = true, bool $prevent_null = false): void
    {
        if ($stmt instanceof Php_Parser\Node\Expr\Property_Fetch && $stmt->name instanceof Php_Parser\Node\Identifier) {
            $prop_name = $stmt->name->name;
            Instance_Property_Assignment_Analyzer::analyze($statements_analyzer, $stmt, $prop_name, null, $by_ref_out_type, $context);
            return;
        }
        $var_id = Expression_Identifier::get_var_id($stmt, $statements_analyzer->get_fqcln(), $statements_analyzer);
        if ($var_id) {
            $var_not_in_scope = false;
            if (!$by_ref_type->has_mixed() && $constrain_type) {
                $context->byref_constraints[$var_id] = new Reference_Constraint($by_ref_type);
            }
            if (!$context->has_variable($var_id)) {
                $context->vars_possibly_in_scope[$var_id] = true;
                $location = new Code_Location($statements_analyzer->get_source(), $stmt);
                if (!$statements_analyzer->has_variable($var_id)) {
                    if ($constrain_type && $prevent_null && !$by_ref_type->is_mixed() && !$by_ref_type->is_nullable() && !strpos($var_id, '->') && !strpos($var_id, '::')) {
                        Issue_Buffer::maybe_add(new Null_Reference('Not expecting null argument passed by reference', $location), $statements_analyzer->get_suppressed_issues());
                    }
                    if ($stmt instanceof Php_Parser\Node\Expr\Variable) {
                        $statements_analyzer->register_variable($var_id, $location, $context->branch_point);
                        if ($statements_analyzer->data_flow_graph instanceof Variable_Use_Graph) {
                            $byref_node = Data_Flow_Node::get_for_assignment($var_id, $location);
                            $statements_analyzer->data_flow_graph->add_path($byref_node, new Data_Flow_Node('variable-use', 'variable use', null), 'variable-use');
                        }
                    }
                    $context->has_variable($var_id);
                } else {
                    $var_not_in_scope = true;
                }
            } elseif ($var_id === '$this') {
                // don't allow changing $this
                return;
            } else {
                $existing_type = $context->vars_in_scope[$var_id];
                // removes dependent vars from $context
                $context->remove_descendents($var_id, $existing_type, $by_ref_type, $statements_analyzer);
                $by_ref_out_type = $by_ref_out_type->add_parent_nodes($existing_type->parent_nodes);
                if (!$context->inside_conditional) {
                    $context->vars_in_scope[$var_id] = $by_ref_out_type;
                    if (!($stmt_type = $statements_analyzer->node_data->get_type($stmt)) || $stmt_type->is_never()) {
                        $statements_analyzer->node_data->set_type($stmt, $by_ref_type);
                    }
                    return;
                }
            }
            $context->assigned_var_ids[$var_id] = (int) $stmt->get_attribute('startFilePos');
            $context->vars_in_scope[$var_id] = $by_ref_out_type;
            $stmt_type = $statements_analyzer->node_data->get_type($stmt);
            if (!$stmt_type || $stmt_type->is_never()) {
                $statements_analyzer->node_data->set_type($stmt, $by_ref_type);
            }
            if ($var_not_in_scope && $stmt instanceof Php_Parser\Node\Expr\Variable) {
                $statements_analyzer->register_possibly_undefined_variable($var_id, $stmt);
            }
        }
    }
    /**
     * @param PhpParser\Node\Expr\List_|PhpParser\Node\Expr\Array_ $assign_var
     * @param list<VarDocblockComment> $var_comments
     * @param list<string> $removed_taints
     */
    private static function analyze_destructuring_assignment(Statements_Analyzer $statements_analyzer, Codebase $codebase, Php_Parser\Node\Expr $assign_var, ?Php_Parser\Node\Expr $assign_value, Union $assign_value_type, Context $context, ?Php_Parser\Comment\Doc $doc_comment, ?string $extended_var_id, array $var_comments, array $removed_taints): void
    {
        if (!$assign_value_type->has_array() && !$assign_value_type->is_mixed() && !$assign_value_type->has_array_access_interface($codebase)) {
            Issue_Buffer::maybe_add(new Invalid_Array_Offset('Cannot destructure non-array of type ' . $assign_value_type->get_id(), new Code_Location($statements_analyzer->get_source(), $assign_var)), $statements_analyzer->get_suppressed_issues());
        }
        $can_be_empty = true;
        foreach ($assign_var->items as $offset => $assign_var_item) {
            // $assign_var_item can be null e.g. list($a, ) = ['a', 'b']
            if (!$assign_var_item) {
                continue;
            }
            $var = $assign_var_item->value;
            if ($assign_value instanceof Php_Parser\Node\Expr\Array_ && $statements_analyzer->node_data->get_type($assign_var_item->value)) {
                self::analyze($statements_analyzer, $var, $assign_var_item->value, null, $context, $doc_comment);
                continue;
            }
            $offset_value = null;
            if (!$assign_var_item->key) {
                $offset_value = $offset;
            } elseif ($assign_var_item->key instanceof Php_Parser\Node\Scalar\String_) {
                $offset_value = $assign_var_item->key->value;
            }
            if ($offset_value !== null) {
                $string_to_int = Array_Analyzer::get_literal_array_key_int($offset_value);
                if ($string_to_int !== false) {
                    $offset_value = $string_to_int;
                }
            }
            $list_var_id = Expression_Identifier::get_extended_var_id($var, $statements_analyzer->get_fqcln(), $statements_analyzer);
            $new_assign_type = null;
            $assigned = false;
            $has_null = false;
            foreach ($assign_value_type->get_atomic_types() as $assign_value_atomic_type) {
                if ($assign_value_atomic_type instanceof T_Keyed_Array && !$assign_var_item->key) {
                    // if object-like has int offsets
                    if ($offset_value !== null && isset($assign_value_atomic_type->properties[$offset_value])) {
                        $value_type = $assign_value_atomic_type->properties[$offset_value];
                        if ($value_type->possibly_undefined) {
                            Issue_Buffer::maybe_add(new Possibly_Undefined_Array_Offset('Possibly undefined array key', new Code_Location($statements_analyzer->get_source(), $var)), $statements_analyzer->get_suppressed_issues());
                            $value_type = $value_type->set_possibly_undefined(false);
                        } else {
                            $can_be_empty = false;
                        }
                        if ($statements_analyzer->data_flow_graph && $assign_value) {
                            $assign_value_id = Expression_Identifier::get_extended_var_id($assign_value, $statements_analyzer->get_fqcln(), $statements_analyzer);
                            $keyed_array_var_id = null;
                            if ($assign_value_id) {
                                $keyed_array_var_id = $assign_value_id . '[\'' . $offset_value . '\']';
                            }
                            $temp = Type::get_string((string) $offset_value);
                            Array_Fetch_Analyzer::taint_array_fetch($statements_analyzer, $assign_value, $keyed_array_var_id, $value_type, $temp);
                        }
                        self::analyze($statements_analyzer, $var, null, $value_type, $context, $doc_comment);
                        $assigned = true;
                        continue;
                    }
                    if ($assign_value_atomic_type->fallback_params === null) {
                        Issue_Buffer::maybe_add(new Invalid_Array_Offset('Cannot access value with offset ' . $offset, new Code_Location($statements_analyzer->get_source(), $var)), $statements_analyzer->get_suppressed_issues());
                    }
                }
                if ($assign_value_atomic_type instanceof T_Mixed) {
                    Issue_Buffer::maybe_add(new Mixed_Array_Access('Cannot access array value on mixed variable ' . $extended_var_id, new Code_Location($statements_analyzer->get_source(), $var)), $statements_analyzer->get_suppressed_issues());
                } elseif ($assign_value_atomic_type instanceof T_Null) {
                    $has_null = true;
                } elseif (!$assign_value_atomic_type instanceof T_Array && !$assign_value_atomic_type instanceof T_Keyed_Array && !$assign_value_type->has_array_access_interface($codebase)) {
                    if ($assign_value_type->has_array()) {
                        if ($assign_value_atomic_type instanceof T_False && $assign_value_type->ignore_falsable_issues) {
                            // do nothing
                        } else {
                            Issue_Buffer::maybe_add(new Possibly_Invalid_Array_Access('Cannot access array value on non-array variable ' . $extended_var_id . ' of type ' . $assign_value_atomic_type->get_id(), new Code_Location($statements_analyzer->get_source(), $var)), $statements_analyzer->get_suppressed_issues());
                        }
                    } else {
                        Issue_Buffer::maybe_add(new Invalid_Array_Access('Cannot access array value on non-array variable ' . $extended_var_id . ' of type ' . $assign_value_atomic_type->get_id(), new Code_Location($statements_analyzer->get_source(), $var)), $statements_analyzer->get_suppressed_issues());
                    }
                }
                if ($var instanceof Php_Parser\Node\Expr\List_ || $var instanceof Php_Parser\Node\Expr\Array_) {
                    if ($assign_value_atomic_type instanceof T_Keyed_Array) {
                        $assign_value_atomic_type = $assign_value_atomic_type->get_generic_array_type();
                    }
                    $array_value_type = $assign_value_atomic_type instanceof T_Array ? $assign_value_atomic_type->type_params[1] : Type::get_mixed();
                    self::analyze($statements_analyzer, $var, null, $array_value_type, $context, $doc_comment);
                    continue;
                }
                if ($list_var_id) {
                    $context->vars_possibly_in_scope[$list_var_id] = true;
                    $context->assigned_var_ids[$list_var_id] = (int) $var->get_attribute('startFilePos');
                    $context->possibly_assigned_var_ids[$list_var_id] = true;
                    $already_in_scope = isset($context->vars_in_scope[$list_var_id]);
                    if (!str_contains($list_var_id, '-') && !str_contains($list_var_id, '[')) {
                        $location = new Code_Location($statements_analyzer, $var);
                        if (!$statements_analyzer->has_variable($list_var_id)) {
                            $statements_analyzer->register_variable($list_var_id, $location, $context->branch_point);
                        } else {
                            $statements_analyzer->register_variable_assignment($list_var_id, $location);
                        }
                        if (isset($context->byref_constraints[$list_var_id])) {
                            // something
                        }
                    }
                    if ($assign_value_atomic_type instanceof T_Array) {
                        $new_assign_type = $assign_value_atomic_type->type_params[1];
                        if ($statements_analyzer->data_flow_graph && $assign_value) {
                            $temp = Type::get_array_key();
                            Array_Fetch_Analyzer::taint_array_fetch($statements_analyzer, $assign_value, null, $new_assign_type, $temp);
                        }
                        $can_be_empty = !$assign_value_atomic_type instanceof T_Non_Empty_Array;
                    } elseif ($assign_value_atomic_type instanceof T_Keyed_Array) {
                        if (($assign_var_item->key instanceof Php_Parser\Node\Scalar\String_ || $assign_var_item->key instanceof Php_Parser\Node\Scalar\Int_) && isset($assign_value_atomic_type->properties[$assign_var_item->key->value])) {
                            $new_assign_type = $assign_value_atomic_type->properties[$assign_var_item->key->value];
                            if ($new_assign_type->possibly_undefined) {
                                Issue_Buffer::maybe_add(new Possibly_Undefined_Array_Offset('Possibly undefined array key', new Code_Location($statements_analyzer->get_source(), $var)), $statements_analyzer->get_suppressed_issues());
                                $new_assign_type = $new_assign_type->set_possibly_undefined(false);
                            } else {
                                $can_be_empty = false;
                            }
                        } elseif (!$assign_var_item->key instanceof Php_Parser\Node\Scalar\String_ && $assign_value_atomic_type->is_list && $assign_value_atomic_type->fallback_params) {
                            if ($codebase->config->ensure_array_int_offsets_exist) {
                                Issue_Buffer::maybe_add(new Possibly_Undefined_Int_Array_Offset('Possibly undefined array key', new Code_Location($statements_analyzer->get_source(), $var)), $statements_analyzer->get_suppressed_issues());
                            }
                            $new_assign_type = $assign_value_atomic_type->fallback_params[1];
                        }
                        if ($statements_analyzer->data_flow_graph && $assign_value && $new_assign_type) {
                            $temp = Type::get_array_key();
                            Array_Fetch_Analyzer::taint_array_fetch($statements_analyzer, $assign_value, null, $new_assign_type, $temp);
                        }
                    } elseif ($assign_value_atomic_type->has_array_access_interface($codebase)) {
                        Foreach_Analyzer::get_key_value_params_for_traversable_object($assign_value_atomic_type, $codebase, $array_access_key_type, $array_access_value_type);
                        $new_assign_type = $array_access_value_type;
                    }
                    if ($already_in_scope) {
                        // removes dependent vars from $context
                        $context->remove_descendents($list_var_id, $context->vars_in_scope[$list_var_id], $new_assign_type, $statements_analyzer);
                    }
                }
            }
            if (!$assigned) {
                if ($has_null) {
                    Issue_Buffer::maybe_add(new Possibly_Null_Array_Access('Cannot access array value on null variable ' . $extended_var_id, new Code_Location($statements_analyzer->get_source(), $var)), $statements_analyzer->get_suppressed_issues());
                }
                foreach ($var_comments as $var_comment) {
                    if (!$var_comment->type) {
                        continue;
                    }
                    try {
                        if ($var_comment->var_id === $list_var_id) {
                            $var_comment_type = Type_Expander::expand_union($codebase, $var_comment->type, $context->self, $context->self, $statements_analyzer->get_parent_fqcln());
                            $var_comment_type = $var_comment_type->set_from_docblock();
                            $new_assign_type = $var_comment_type;
                            break;
                        }
                    } catch (UnexpectedValueException $e) {
                        Issue_Buffer::maybe_add(new Invalid_Docblock($e->get_message(), new Code_Location($statements_analyzer->get_source(), $assign_var)));
                    }
                }
                if ($list_var_id) {
                    $context->vars_in_scope[$list_var_id] = $new_assign_type ?: Type::get_mixed();
                    if ($statements_analyzer->data_flow_graph) {
                        $data_flow_graph = $statements_analyzer->data_flow_graph;
                        $var_location = new Code_Location($statements_analyzer->get_source(), $var);
                        if (!$context->vars_in_scope[$list_var_id]->parent_nodes) {
                            $assignment_node = Data_Flow_Node::get_for_assignment($list_var_id, $var_location);
                            $context->vars_in_scope[$list_var_id] = $context->vars_in_scope[$list_var_id]->set_parent_nodes([$assignment_node->id => $assignment_node]);
                        } else if ($data_flow_graph instanceof Taint_Flow_Graph && in_array('TaintedInput', $statements_analyzer->get_suppressed_issues())) {
                            $context->vars_in_scope[$list_var_id] = $context->vars_in_scope[$list_var_id]->set_parent_nodes([]);
                        } else {
                            $event = new Add_Remove_Taints_Event($var, $context, $statements_analyzer, $codebase);
                            $added_taints = $codebase->config->event_dispatcher->dispatch_add_taints($event);
                            $removed_taints = [...$removed_taints, ...$codebase->config->event_dispatcher->dispatch_remove_taints($event)];
                            self::taint_assignment($context->vars_in_scope[$list_var_id], $data_flow_graph, $list_var_id, $var_location, $removed_taints, $added_taints);
                        }
                    }
                }
            }
            if ($list_var_id) {
                if ($context->error_suppressing && ($offset || $can_be_empty) || $has_null) {
                    $context->vars_in_scope[$list_var_id] = $context->vars_in_scope[$list_var_id]->get_builder()->add_type(new T_Null())->freeze();
                }
            }
        }
    }
    private static function analyze_property_assignment(Statements_Analyzer $statements_analyzer, Codebase $codebase, Php_Parser\Node\Expr\Property_Fetch $assign_var, Context $context, ?Php_Parser\Node\Expr $assign_value, Union $assign_value_type, ?string $var_id): void
    {
        if (!$assign_var->name instanceof Php_Parser\Node\Identifier) {
            $was_inside_general_use = $context->inside_general_use;
            $context->inside_general_use = true;
            // this can happen when the user actually means to type $this-><autocompleted>, but there's
            // a variable on the next line
            if (Expression_Analyzer::analyze($statements_analyzer, $assign_var->var, $context) === false) {
                $context->inside_general_use = $was_inside_general_use;
                return;
            }
            if (Expression_Analyzer::analyze($statements_analyzer, $assign_var->name, $context) === false) {
                $context->inside_general_use = $was_inside_general_use;
                return;
            }
            $context->inside_general_use = $was_inside_general_use;
        }
        if ($assign_var->name instanceof Php_Parser\Node\Identifier) {
            $prop_name = $assign_var->name->name;
        } elseif (($assign_var_name_type = $statements_analyzer->node_data->get_type($assign_var->name)) && $assign_var_name_type->is_single_string_literal()) {
            $prop_name = $assign_var_name_type->get_single_string_literal()->value;
        } else {
            $prop_name = null;
        }
        if ($prop_name) {
            Instance_Property_Assignment_Analyzer::analyze($statements_analyzer, $assign_var, $prop_name, $assign_value, $assign_value_type, $context);
        } else {
            if (Expression_Analyzer::analyze($statements_analyzer, $assign_var->var, $context) === false) {
                return;
            }
            if (($assign_var_type = $statements_analyzer->node_data->get_type($assign_var->var)) && !$context->ignore_variable_property) {
                $stmt_var_type = $assign_var_type;
                if ($stmt_var_type->has_object_type()) {
                    foreach ($stmt_var_type->get_atomic_types() as $type) {
                        if ($type instanceof T_Named_Object) {
                            $codebase->analyzer->add_mixed_member_name(strtolower($type->value) . '::$', $context->calling_method_id ?: $statements_analyzer->get_file_name());
                        }
                    }
                }
            }
        }
        if ($var_id) {
            $context->vars_possibly_in_scope[$var_id] = true;
        }
        $property_var_pure_compatible = $statements_analyzer->node_data->is_pure_compatible($assign_var->var);
        // prevents writing to any properties in a mutation-free context
        if (!$property_var_pure_compatible && !$context->collect_mutations && !$context->collect_initializations) {
            if ($context->mutation_free || $context->external_mutation_free) {
                Issue_Buffer::maybe_add(new Impure_Property_Assignment('Cannot assign to a property from a mutation-free context', new Code_Location($statements_analyzer, $assign_var)), $statements_analyzer->get_suppressed_issues());
            } elseif ($statements_analyzer->get_source() instanceof Function_Like_Analyzer && $statements_analyzer->get_source()->track_mutations) {
                if (!$assign_var->var instanceof Php_Parser\Node\Expr\Variable || $assign_var->var->name !== 'this') {
                    $statements_analyzer->get_source()->inferred_has_mutation = true;
                }
                $statements_analyzer->get_source()->inferred_impure = true;
            }
        }
    }
    private static function analyze_assignment_to_variable(Statements_Analyzer $statements_analyzer, Codebase $codebase, Php_Parser\Node\Expr\Variable $assign_var, ?Php_Parser\Node\Expr $assign_value, Union $assign_value_type, ?string $var_id, Context $context): void
    {
        if (is_string($assign_var->name)) {
            if ($var_id) {
                $original_type = $context->vars_in_scope[$var_id] ?? null;
                $context->vars_in_scope[$var_id] = $assign_value_type;
                $context->vars_possibly_in_scope[$var_id] = true;
                $location = new Code_Location($statements_analyzer, $assign_var);
                if (!$statements_analyzer->has_variable($var_id)) {
                    $statements_analyzer->register_variable($var_id, $location, $context->branch_point);
                } elseif (!$context->inside_isset) {
                    $statements_analyzer->register_variable_assignment($var_id, $location);
                }
                if ($codebase->store_node_types && !$context->collect_initializations && !$context->collect_mutations) {
                    $location = new Code_Location($statements_analyzer, $assign_var);
                    $codebase->analyzer->add_node_reference($statements_analyzer->get_file_path(), $assign_var, $location->raw_file_start . '-' . $location->raw_file_end . ':' . $assign_value_type->get_id());
                }
                if (isset($context->byref_constraints[$var_id])) {
                    $assign_value_type = $assign_value_type->set_by_ref(true);
                }
                if ($statements_analyzer->data_flow_graph instanceof Variable_Use_Graph && $assign_value_type->parent_nodes) {
                    if (isset($context->references_to_external_scope[$var_id]) || isset($context->references_in_scope[$var_id]) || isset($context->referenced_counts[$var_id]) && $context->referenced_counts[$var_id] > 0) {
                        $location = new Code_Location($statements_analyzer, $assign_var);
                        $assignment_node = Data_Flow_Node::get_for_assignment($var_id, $location);
                        $parent_nodes = $assign_value_type->parent_nodes;
                        if ($original_type !== null) {
                            $parent_nodes += $original_type->parent_nodes;
                        }
                        foreach ($parent_nodes as $parent_node) {
                            $statements_analyzer->data_flow_graph->add_path($parent_node, $assignment_node, '&=');
                        }
                        if (isset($context->references_to_external_scope[$var_id])) {
                            // Mark reference to an external scope as used when a value is assigned to it
                            $statements_analyzer->data_flow_graph->add_path($assignment_node, new Data_Flow_Node('variable-use', 'variable use', null), 'variable-use');
                        }
                    }
                }
                if (isset($context->references_possibly_from_confusing_scope[$var_id])) {
                    Issue_Buffer::maybe_add(new Reference_Reused_From_Confusing_Scope("{$var_id} is possibly a reference defined at" . " {$context->references_possibly_from_confusing_scope[$var_id]->get_short_summary()}." . " Reusing this variable may cause the referenced value to change.", new Code_Location($statements_analyzer, $assign_var)), $statements_analyzer->get_suppressed_issues());
                }
                if ($assign_value_type->get_id() === 'bool' && ($assign_value instanceof Php_Parser\Node\Expr\Binary_Op || $assign_value instanceof Php_Parser\Node\Expr\Boolean_Not && $assign_value->expr instanceof Php_Parser\Node\Expr\Binary_Op)) {
                    $var_object_id = spl_object_id($assign_var);
                    $cond_object_id = spl_object_id($assign_value);
                    $right_clauses = Formula_Generator::get_formula($cond_object_id, $cond_object_id, $assign_value, $context->self, $statements_analyzer, $codebase);
                    $right_clauses = Context::filter_clauses($var_id, $right_clauses);
                    $assignment_clauses = Algebra::combine_ored_clauses([new Clause([$var_id => ['falsy' => new Falsy()]], $var_object_id, $var_object_id)], $right_clauses, $cond_object_id);
                    $context->clauses = [...$context->clauses, ...$assignment_clauses];
                }
            }
        } else {
            $was_inside_general_use = $context->inside_general_use;
            $context->inside_general_use = true;
            if (Expression_Analyzer::analyze($statements_analyzer, $assign_var->name, $context) === false) {
                $context->inside_general_use = $was_inside_general_use;
                return;
            }
            $context->inside_general_use = $was_inside_general_use;
            if ($statements_analyzer->data_flow_graph instanceof Variable_Use_Graph && $assign_value_type->parent_nodes) {
                foreach ($assign_value_type->parent_nodes as $parent_node) {
                    $statements_analyzer->data_flow_graph->add_path($parent_node, new Data_Flow_Node('variable-use', 'variable use', null), 'variable-use');
                }
            }
        }
    }
    private static function analyze_variable_use(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr $assign_var, ?string $extended_var_id, Union $assign_value_type, Context $context): Union
    {
        if ($extended_var_id) {
            $assignment_node = Data_Flow_Node::get_for_assignment($extended_var_id, new Code_Location($statements_analyzer->get_source(), $assign_var));
        } else {
            $assignment_node = new Data_Flow_Node('unknown-origin', 'unknown origin', null);
        }
        $parent_nodes = [$assignment_node->id => $assignment_node];
        if ($context->inside_try) {
            // Copy previous assignment's parent nodes inside a try. Since an exception could be thrown at any
            // point this is a workaround to ensure that use of a variable also uses all previous assignments.
            if ($extended_var_id !== null && isset($context->vars_in_scope[$extended_var_id])) {
                $parent_nodes += $context->vars_in_scope[$extended_var_id]->parent_nodes;
            }
        }
        return $assign_value_type->set_parent_nodes($parent_nodes);
    }
    /**
     * @param list<string> $removed_taints
     */
    private static function analyze_assign_value_data_flow(Statements_Analyzer $statements_analyzer, Codebase $codebase, Php_Parser\Node\Expr $assign_var, ?Php_Parser\Node\Expr $assign_expr, Union &$assign_value_type, string $var_id, Context $context, array $removed_taints): void
    {
        if (!$statements_analyzer->data_flow_graph || !$context->vars_in_scope[$var_id]->parent_nodes) {
            return;
        }
        $data_flow_graph = $statements_analyzer->data_flow_graph;
        if ($data_flow_graph instanceof Taint_Flow_Graph && in_array('TaintedInput', $statements_analyzer->get_suppressed_issues())) {
            $context->vars_in_scope[$var_id] = $context->vars_in_scope[$var_id]->set_parent_nodes([]);
        } else {
            $var_location = new Code_Location($statements_analyzer->get_source(), $assign_var);
            $event = new Add_Remove_Taints_Event($assign_var, $context, $statements_analyzer, $codebase);
            $added_taints = $codebase->config->event_dispatcher->dispatch_add_taints($event);
            $removed_taints = [...$removed_taints, ...$codebase->config->event_dispatcher->dispatch_remove_taints($event)];
            self::taint_assignment($context->vars_in_scope[$var_id], $data_flow_graph, $var_id, $var_location, $removed_taints, $added_taints);
        }
        if ($assign_expr) {
            $new_parent_node = Data_Flow_Node::get_for_assignment('assignment_expr', new Code_Location($statements_analyzer->get_source(), $assign_expr));
            $data_flow_graph->add_node($new_parent_node);
            foreach ($context->vars_in_scope[$var_id]->parent_nodes as $old_parent_node) {
                $data_flow_graph->add_path($old_parent_node, $new_parent_node, '=');
            }
            $assign_value_type = $assign_value_type->set_parent_nodes([$new_parent_node->id => $new_parent_node]);
        }
    }
}