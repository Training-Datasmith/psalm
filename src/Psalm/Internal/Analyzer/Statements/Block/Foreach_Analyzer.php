<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Block;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Code_Location\Docblock_Type_Location;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Exception\Docblock_Parse_Exception;
use Psalm\Internal\Analyzer\Class_Like_Analyzer;
use Psalm\Internal\Analyzer\Class_Like_Name_Options;
use Psalm\Internal\Analyzer\Comment_Analyzer;
use Psalm\Internal\Analyzer\Function_Like_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Assignment_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call\Method_Call_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Expression_Identifier;
use Psalm\Internal\Analyzer\Statements\Expression\Fetch\Array_Fetch_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Fetch\Variable_Fetch_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\File_Manipulation\File_Manipulation_Buffer;
use Psalm\Internal\Scope\Loop_Scope;
use Psalm\Internal\Type\Assertion_Reconciler;
use Psalm\Internal\Type\Comparator\Atomic_Type_Comparator;
use Psalm\Internal\Type\Type_Expander;
use Psalm\Issue\Impure_Method_Call;
use Psalm\Issue\Invalid_Docblock;
use Psalm\Issue\Invalid_Iterator;
use Psalm\Issue\Null_Iterator;
use Psalm\Issue\Possible_Raw_Object_Iteration;
use Psalm\Issue\Possibly_False_Iterator;
use Psalm\Issue\Possibly_Invalid_Iterator;
use Psalm\Issue\Possibly_Null_Iterator;
use Psalm\Issue\Raw_Object_Iteration;
use Psalm\Issue\Unnecessary_Var_Annotation;
use Psalm\Issue_Buffer;
use Psalm\Node\Expr\Virtual_Method_Call;
use Psalm\Node\Virtual_Identifier;
use Psalm\Storage\Assertion;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\Scalar;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Callable_Object;
use Psalm\Type\Atomic\T_False;
use Psalm\Type\Atomic\T_Generic_Object;
use Psalm\Type\Atomic\T_Iterable;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Atomic\T_Mixed;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Never;
use Psalm\Type\Atomic\T_Non_Empty_Array;
use Psalm\Type\Atomic\T_Null;
use Psalm\Type\Atomic\T_Object;
use Psalm\Type\Atomic\T_Object_With_Properties;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Atomic\T_Void;
use Psalm\Type\Union;
use UnexpectedValueException;
use function array_keys;
use function array_map;
use function array_search;
use function array_values;
use function assert;
use function in_array;
use function is_string;
use function reset;
use function stripos;
use function strtolower;
/**
 * @internal
 */
final class Foreach_Analyzer
{
    /**
     * @return  false|null
     */
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Stmt\Foreach_ $stmt, Context $context): ?bool
    {
        $var_comments = [];
        $doc_comment = $stmt->get_doc_comment();
        $codebase = $statements_analyzer->get_codebase();
        $file_path = $statements_analyzer->get_root_file_path();
        $type_aliases = $codebase->file_storage_provider->get($file_path)->type_aliases;
        if ($doc_comment) {
            try {
                $var_comments = Comment_Analyzer::get_type_from_comment($doc_comment, $statements_analyzer->get_source(), $statements_analyzer->get_source()->get_aliases(), $statements_analyzer->get_template_type_map() ?: [], $type_aliases);
            } catch (Docblock_Parse_Exception $e) {
                Issue_Buffer::maybe_add(new Invalid_Docblock($e->get_message(), new Code_Location($statements_analyzer, $stmt)));
            }
        }
        $safe_var_ids = [];
        if ($stmt->key_var instanceof Php_Parser\Node\Expr\Variable && is_string($stmt->key_var->name)) {
            $safe_var_ids['$' . $stmt->key_var->name] = true;
        }
        if ($stmt->value_var instanceof Php_Parser\Node\Expr\Variable && is_string($stmt->value_var->name)) {
            $safe_var_ids['$' . $stmt->value_var->name] = true;
            $statements_analyzer->foreach_var_locations['$' . $stmt->value_var->name][] = new Code_Location($statements_analyzer, $stmt->value_var);
        } elseif ($stmt->value_var instanceof Php_Parser\Node\Expr\List_) {
            foreach ($stmt->value_var->items as $list_item) {
                if (!$list_item) {
                    continue;
                }
                $list_item_key = $list_item->key;
                $list_item_value = $list_item->value;
                if ($list_item_value instanceof Php_Parser\Node\Expr\Variable && is_string($list_item_value->name)) {
                    $safe_var_ids['$' . $list_item_value->name] = true;
                }
                if ($list_item_key instanceof Php_Parser\Node\Expr\Variable && is_string($list_item_key->name)) {
                    $safe_var_ids['$' . $list_item_key->name] = true;
                }
            }
        }
        foreach ($var_comments as $var_comment) {
            if (!$var_comment->var_id) {
                continue;
            }
            if (!$var_comment->type) {
                continue;
            }
            if (isset($safe_var_ids[$var_comment->var_id])) {
                continue;
            }
            $comment_type = Type_Expander::expand_union($codebase, $var_comment->type, $context->self, $context->self, $statements_analyzer->get_parent_fqcln());
            $type_location = null;
            if ($var_comment->type_start && $var_comment->type_end && $var_comment->line_number) {
                $type_location = new Docblock_Type_Location($statements_analyzer, $var_comment->type_start, $var_comment->type_end, $var_comment->line_number);
                if ($codebase->alter_code) {
                    $codebase->classlikes->handle_docblock_type_in_migration($codebase, $statements_analyzer, $comment_type, $type_location, $context->calling_method_id);
                }
            }
            if (isset($context->vars_in_scope[$var_comment->var_id]) || Variable_Fetch_Analyzer::is_super_global($var_comment->var_id)) {
                if ($codebase->find_unused_variables && $doc_comment && $type_location && isset($context->vars_in_scope[$var_comment->var_id]) && $context->vars_in_scope[$var_comment->var_id]->get_id() === $comment_type->get_id() && !$comment_type->is_mixed(true)) {
                    $project_analyzer = $statements_analyzer->get_project_analyzer();
                    if ($codebase->alter_code && isset($project_analyzer->get_issues_to_fix()['UnnecessaryVarAnnotation'])) {
                        File_Manipulation_Buffer::add_var_annotation_to_remove($type_location);
                    } else {
                        Issue_Buffer::maybe_add(new Unnecessary_Var_Annotation('The @var ' . $comment_type . ' annotation for ' . $var_comment->var_id . ' is unnecessary', $type_location), $statements_analyzer->get_suppressed_issues(), true);
                    }
                }
                if (isset($context->vars_in_scope[$var_comment->var_id])) {
                    /** @psalm-suppress InaccessibleProperty We just created this type */
                    $comment_type->parent_nodes = $context->vars_in_scope[$var_comment->var_id]->parent_nodes;
                }
                $context->vars_in_scope[$var_comment->var_id] = $comment_type;
            }
        }
        $was_inside_general_use = $context->inside_general_use;
        $context->inside_general_use = true;
        if (Expression_Analyzer::analyze($statements_analyzer, $stmt->expr, $context) === false) {
            $context->inside_general_use = $was_inside_general_use;
            return false;
        }
        $context->inside_general_use = $was_inside_general_use;
        $key_type = null;
        $value_type = null;
        $always_non_empty_array = true;
        $var_id = Expression_Identifier::get_var_id($stmt->expr, $statements_analyzer->get_fqcln(), $statements_analyzer);
        if ($stmt_expr_type = $statements_analyzer->node_data->get_type($stmt->expr)) {
            $iterator_type = $stmt_expr_type;
        } elseif ($var_id && $context->has_variable($var_id)) {
            $iterator_type = $context->vars_in_scope[$var_id];
        } else {
            $iterator_type = null;
        }
        if ($iterator_type) {
            if (self::check_iterator_type($statements_analyzer, $stmt, $stmt->expr, $iterator_type, $codebase, $context, $key_type, $value_type, $always_non_empty_array) === false) {
                return false;
            }
        }
        $foreach_context = clone $context;
        if ($var_id && $foreach_context->has_variable($var_id)) {
            // refine the type of the array variable we iterate over
            // if we entered loop body, the array cannot be empty
            $foreach_context->vars_in_scope[$var_id] = Assertion_Reconciler::reconcile(
                new Assertion\Non_Empty(),
                $foreach_context->vars_in_scope[$var_id],
                null,
                $statements_analyzer,
                true,
                // inside loop ?
                $statements_analyzer->get_template_type_map() ?? []
            );
        }
        $foreach_context->inside_loop = true;
        $foreach_context->break_types[] = 'loop';
        if ($codebase->alter_code && $foreach_context->branch_point === null) {
            $foreach_context->branch_point = (int) $stmt->get_attribute('startFilePos');
        }
        if ($stmt->key_var instanceof Php_Parser\Node\Expr\Variable && is_string($stmt->key_var->name)) {
            $key_type ??= Type::get_mixed();
            Assignment_Analyzer::analyze($statements_analyzer, $stmt->key_var, $stmt->expr, $key_type, $foreach_context, $doc_comment, ['$' . $stmt->key_var->name => true]);
        }
        if ($value_type !== null) {
            $value_type = $value_type->set_properties(['by_ref' => $stmt->by_ref]);
        } else {
            $value_type = new Union([new T_Mixed()], ['by_ref' => $stmt->by_ref]);
        }
        if ($stmt->by_ref && $stmt->value_var instanceof Php_Parser\Node\Expr\Variable && is_string($stmt->value_var->name)) {
            // When assigning as reference, it removes any previous
            // reference, so it's no longer from a previous confusing scope
            unset($foreach_context->references_possibly_from_confusing_scope['$' . $stmt->value_var->name]);
        }
        Assignment_Analyzer::analyze($statements_analyzer, $stmt->value_var, $stmt->expr, $value_type, $foreach_context, $doc_comment, $stmt->value_var instanceof Php_Parser\Node\Expr\Variable && is_string($stmt->value_var->name) ? ['$' . $stmt->value_var->name => true] : []);
        if ($stmt->by_ref && $stmt->value_var instanceof Php_Parser\Node\Expr\Variable && is_string($stmt->value_var->name)) {
            // TODO support references with destructuring
            $foreach_context->references_to_external_scope['$' . $stmt->value_var->name] = true;
        }
        foreach ($var_comments as $var_comment) {
            if (!$var_comment->var_id) {
                continue;
            }
            if (!$var_comment->type) {
                continue;
            }
            $comment_type = Type_Expander::expand_union($codebase, $var_comment->type, $context->self, $context->self, $statements_analyzer->get_parent_fqcln());
            if (isset($foreach_context->vars_in_scope[$var_comment->var_id])) {
                $existing_var_type = $foreach_context->vars_in_scope[$var_comment->var_id];
                /** @psalm-suppress InaccessibleProperty We just created this type */
                $comment_type->parent_nodes = $existing_var_type->parent_nodes;
                /** @psalm-suppress InaccessibleProperty We just created this type */
                $comment_type->by_ref = $existing_var_type->by_ref;
            }
            $foreach_context->vars_in_scope[$var_comment->var_id] = $comment_type;
        }
        $loop_scope = new Loop_Scope($foreach_context, $context);
        $loop_scope->protected_var_ids = $context->protected_var_ids;
        if (Loop_Analyzer::analyze($statements_analyzer, $stmt->stmts, [], [], $loop_scope, $inner_loop_context, false, $always_non_empty_array) === false) {
            return false;
        }
        if (!$inner_loop_context) {
            throw new UnexpectedValueException('There should be an inner loop context');
        }
        $foreach_context->loop_scope = null;
        $context->vars_possibly_in_scope = [...$foreach_context->vars_possibly_in_scope, ...$context->vars_possibly_in_scope];
        if ($context->collect_exceptions) {
            $context->merge_exceptions($foreach_context);
        }
        return null;
    }
    /**
     * @param PhpParser\Node\Stmt\Foreach_|PhpParser\Node\Expr\YieldFrom $stmt
     * @return false|null
     */
    public static function check_iterator_type(Statements_Analyzer $statements_analyzer, Php_Parser\Node_Abstract $stmt, Php_Parser\Node\Expr $expr, Union $iterator_type, Codebase $codebase, Context $context, ?Union &$key_type, ?Union &$value_type, bool &$always_non_empty_array): ?bool
    {
        if ($iterator_type->is_null()) {
            Issue_Buffer::maybe_add(new Null_Iterator('Cannot iterate over null', new Code_Location($statements_analyzer->get_source(), $expr)), $statements_analyzer->get_suppressed_issues());
            return false;
        }
        if ($iterator_type->is_nullable() && !$iterator_type->ignore_nullable_issues) {
            Issue_Buffer::maybe_add(new Possibly_Null_Iterator('Cannot iterate over nullable var ' . $iterator_type, new Code_Location($statements_analyzer->get_source(), $expr)), $statements_analyzer->get_suppressed_issues());
            return null;
        }
        if ($iterator_type->is_falsable() && !$iterator_type->ignore_falsable_issues) {
            Issue_Buffer::maybe_add(new Possibly_False_Iterator('Cannot iterate over falsable var ' . $iterator_type, new Code_Location($statements_analyzer->get_source(), $expr)), $statements_analyzer->get_suppressed_issues());
            return null;
        }
        $has_valid_iterator = false;
        $invalid_iterator_types = [];
        $raw_object_types = [];
        foreach ($iterator_type->get_atomic_types() as $iterator_atomic_type) {
            if ($iterator_atomic_type instanceof T_Template_Param) {
                $iterator_atomic_type = $iterator_atomic_type->as->get_single_atomic();
            }
            // if it's an empty array, we cannot iterate over it
            if ($iterator_atomic_type instanceof T_Array && $iterator_atomic_type->is_empty_array()) {
                $always_non_empty_array = false;
                $has_valid_iterator = true;
                continue;
            }
            if ($iterator_atomic_type instanceof T_Null || $iterator_atomic_type instanceof T_False) {
                $always_non_empty_array = false;
                continue;
            }
            if ($iterator_atomic_type instanceof T_Array || $iterator_atomic_type instanceof T_Keyed_Array) {
                if ($iterator_atomic_type instanceof T_Keyed_Array) {
                    if (!$iterator_atomic_type->is_non_empty()) {
                        $always_non_empty_array = false;
                    }
                    $iterator_atomic_type = $iterator_atomic_type->get_generic_array_type(Expression_Identifier::get_extended_var_id($expr, $statements_analyzer->get_fqcln(), $statements_analyzer));
                } elseif (!$iterator_atomic_type instanceof T_Non_Empty_Array) {
                    $always_non_empty_array = false;
                }
                $value_type = Type::combine_union_types($value_type, $iterator_atomic_type->type_params[1]);
                $key_type_part = $iterator_atomic_type->type_params[0];
                $key_type = Type::combine_union_types($key_type, $key_type_part);
                Array_Fetch_Analyzer::taint_array_fetch($statements_analyzer, $expr, null, $value_type, $key_type);
                $has_valid_iterator = true;
                continue;
            }
            $always_non_empty_array = false;
            if ($iterator_atomic_type instanceof Scalar || $iterator_atomic_type instanceof T_Void) {
                $invalid_iterator_types[] = $iterator_atomic_type->get_key();
                $value_type = Type::get_mixed();
            } elseif ($iterator_atomic_type instanceof T_Object || $iterator_atomic_type instanceof T_Mixed || $iterator_atomic_type instanceof T_Never) {
                $has_valid_iterator = true;
                $value_type = Type::get_mixed();
                $key_type = Type::get_mixed();
                Array_Fetch_Analyzer::taint_array_fetch($statements_analyzer, $expr, null, $value_type, $key_type);
                if (!$context->pure) {
                    if ($statements_analyzer->get_source() instanceof Function_Like_Analyzer && $statements_analyzer->get_source()->track_mutations) {
                        $statements_analyzer->get_source()->inferred_has_mutation = true;
                        $statements_analyzer->get_source()->inferred_impure = true;
                    }
                } else {
                    Issue_Buffer::maybe_add(new Impure_Method_Call('Cannot call a possibly-mutating iterator from a pure context', new Code_Location($statements_analyzer, $stmt)), $statements_analyzer->get_suppressed_issues());
                }
            } elseif ($iterator_atomic_type instanceof T_Iterable) {
                if ($iterator_atomic_type->extra_types) {
                    $iterator_atomic_types = [$iterator_atomic_type->set_intersection_types([]), ...$iterator_atomic_type->extra_types];
                } else {
                    $iterator_atomic_types = [$iterator_atomic_type];
                }
                $intersection_value_type = null;
                $intersection_key_type = null;
                foreach ($iterator_atomic_types as $iat) {
                    if (!$iat instanceof T_Iterable) {
                        continue;
                    }
                    [$key_type_part, $value_type_part] = $iat->type_params;
                    if (!$intersection_value_type) {
                        $intersection_value_type = $value_type_part;
                    } else {
                        $intersection_value_type = Type::intersect_union_types($intersection_value_type, $value_type_part, $codebase) ?? Type::get_mixed();
                    }
                    if (!$intersection_key_type) {
                        $intersection_key_type = $key_type_part;
                    } else {
                        $intersection_key_type = Type::intersect_union_types($intersection_key_type, $key_type_part, $codebase) ?? Type::get_mixed();
                    }
                }
                if (!$intersection_value_type || !$intersection_key_type) {
                    throw new UnexpectedValueException('Should not happen');
                }
                $value_type = Type::combine_union_types($value_type, $intersection_value_type);
                $key_type = Type::combine_union_types($key_type, $intersection_key_type);
                Array_Fetch_Analyzer::taint_array_fetch($statements_analyzer, $expr, null, $value_type, $key_type);
                $has_valid_iterator = true;
                if (!$context->pure) {
                    if ($statements_analyzer->get_source() instanceof Function_Like_Analyzer && $statements_analyzer->get_source()->track_mutations) {
                        $statements_analyzer->get_source()->inferred_has_mutation = true;
                        $statements_analyzer->get_source()->inferred_impure = true;
                    }
                } else {
                    Issue_Buffer::maybe_add(new Impure_Method_Call('Cannot call a possibly-mutating Traversable::getIterator from a pure context', new Code_Location($statements_analyzer, $stmt)), $statements_analyzer->get_suppressed_issues());
                }
            } elseif ($iterator_atomic_type instanceof T_Named_Object) {
                if ($iterator_atomic_type->value !== 'Traversable' && $iterator_atomic_type->value !== $statements_analyzer->get_class_name()) {
                    if (Class_Like_Analyzer::check_fully_qualified_class_like_name($statements_analyzer, $iterator_atomic_type->value, new Code_Location($statements_analyzer->get_source(), $expr), $context->self, $context->calling_method_id, $statements_analyzer->get_suppressed_issues(), new Class_Like_Name_Options(true)) === false) {
                        return false;
                    }
                }
                if (Atomic_Type_Comparator::is_contained_by($codebase, $iterator_atomic_type, new T_Iterable([Type::get_mixed(), Type::get_mixed()]))) {
                    self::handle_iterable($statements_analyzer, $iterator_atomic_type, $expr, $codebase, $context, $key_type, $value_type, $has_valid_iterator, $invalid_iterator_types);
                } else {
                    $raw_object_types[] = $iterator_atomic_type->value;
                }
                if (!$context->pure) {
                    if ($statements_analyzer->get_source() instanceof Function_Like_Analyzer && $statements_analyzer->get_source()->track_mutations) {
                        $statements_analyzer->get_source()->inferred_has_mutation = true;
                        $statements_analyzer->get_source()->inferred_impure = true;
                    }
                } else {
                    Issue_Buffer::maybe_add(new Impure_Method_Call('Cannot call a possibly-mutating iterator from a pure context', new Code_Location($statements_analyzer, $stmt)), $statements_analyzer->get_suppressed_issues());
                }
            }
        }
        if ($raw_object_types) {
            if ($has_valid_iterator) {
                Issue_Buffer::maybe_add(new Possible_Raw_Object_Iteration('Possibly undesired iteration over regular object ' . reset($raw_object_types), new Code_Location($statements_analyzer->get_source(), $expr)), $statements_analyzer->get_suppressed_issues());
            } else {
                Issue_Buffer::maybe_add(new Raw_Object_Iteration('Possibly undesired iteration over regular object ' . reset($raw_object_types), new Code_Location($statements_analyzer->get_source(), $expr)), $statements_analyzer->get_suppressed_issues());
            }
        }
        if ($invalid_iterator_types) {
            if ($has_valid_iterator) {
                Issue_Buffer::maybe_add(new Possibly_Invalid_Iterator(stripos($invalid_iterator_types[0], 'generator<') === 0 ? 'Cannot iterate over generator with non-null send() type ' . $invalid_iterator_types[0] : 'Cannot iterate over ' . $invalid_iterator_types[0], new Code_Location($statements_analyzer->get_source(), $expr)), $statements_analyzer->get_suppressed_issues());
            } else {
                Issue_Buffer::maybe_add(new Invalid_Iterator(stripos($invalid_iterator_types[0], 'generator<') === 0 ? 'Cannot iterate over generator with non-null send() type ' . $invalid_iterator_types[0] : 'Cannot iterate over ' . $invalid_iterator_types[0], new Code_Location($statements_analyzer->get_source(), $expr)), $statements_analyzer->get_suppressed_issues());
            }
        }
        return null;
    }
    /** @param list<string> $invalid_iterator_types */
    public static function handle_iterable(Statements_Analyzer $statements_analyzer, T_Named_Object $iterator_atomic_type, Php_Parser\Node\Expr $foreach_expr, Codebase $codebase, Context $context, ?Union &$key_type, ?Union &$value_type, bool &$has_valid_iterator, array &$invalid_iterator_types = []): void
    {
        if ($iterator_atomic_type->extra_types) {
            $iterator_atomic_types = [$iterator_atomic_type->set_intersection_types([]), ...$iterator_atomic_type->extra_types];
        } else {
            $iterator_atomic_types = [$iterator_atomic_type];
        }
        foreach ($iterator_atomic_types as $iterator_atomic_type) {
            if ($iterator_atomic_type instanceof T_Template_Param || $iterator_atomic_type instanceof T_Object_With_Properties || $iterator_atomic_type instanceof T_Callable_Object) {
                throw new UnexpectedValueException('Shouldn’t get a generic param here');
            }
            if ($iterator_atomic_type instanceof T_Iterable || (strtolower($iterator_atomic_type->value) === 'traversable' || $codebase->class_implements($iterator_atomic_type->value, 'Traversable') || $codebase->interface_exists($iterator_atomic_type->value) && $codebase->interface_extends($iterator_atomic_type->value, 'Traversable'))) {
                if (strtolower($iterator_atomic_type->value) === 'iteratoraggregate' || $codebase->class_implements($iterator_atomic_type->value, 'IteratorAggregate') || $codebase->interface_exists($iterator_atomic_type->value) && $codebase->interface_extends($iterator_atomic_type->value, 'IteratorAggregate')) {
                    $has_valid_iterator = true;
                    $old_data_provider = $statements_analyzer->node_data;
                    $statements_analyzer->node_data = clone $statements_analyzer->node_data;
                    $fake_method_call = new Virtual_Method_Call($foreach_expr, new Virtual_Identifier('getIterator', $foreach_expr->get_attributes()));
                    $suppressed_issues = $statements_analyzer->get_suppressed_issues();
                    if (!in_array('PossiblyInvalidMethodCall', $suppressed_issues, true)) {
                        $statements_analyzer->add_suppressed_issues(['PossiblyInvalidMethodCall']);
                    }
                    if (!in_array('PossiblyUndefinedMethod', $suppressed_issues, true)) {
                        $statements_analyzer->add_suppressed_issues(['PossiblyUndefinedMethod']);
                    }
                    $was_inside_call = $context->inside_call;
                    $context->inside_call = true;
                    Method_Call_Analyzer::analyze($statements_analyzer, $fake_method_call, $context);
                    $context->inside_call = $was_inside_call;
                    if (!in_array('PossiblyInvalidMethodCall', $suppressed_issues, true)) {
                        $statements_analyzer->remove_suppressed_issues(['PossiblyInvalidMethodCall']);
                    }
                    if (!in_array('PossiblyUndefinedMethod', $suppressed_issues, true)) {
                        $statements_analyzer->remove_suppressed_issues(['PossiblyUndefinedMethod']);
                    }
                    $iterator_class_type = $statements_analyzer->node_data->get_type($fake_method_call) ?? null;
                    $statements_analyzer->node_data = $old_data_provider;
                    if ($iterator_class_type) {
                        foreach ($iterator_class_type->get_atomic_types() as $array_atomic_type) {
                            $key_type_part = null;
                            $value_type_part = null;
                            if ($array_atomic_type instanceof T_Array || $array_atomic_type instanceof T_Keyed_Array) {
                                if ($array_atomic_type instanceof T_Keyed_Array) {
                                    $array_atomic_type = $array_atomic_type->get_generic_array_type();
                                }
                                [$key_type_part, $value_type_part] = $array_atomic_type->type_params;
                            } else {
                                if ($array_atomic_type instanceof T_Named_Object && $codebase->class_exists($array_atomic_type->value) && $codebase->class_implements($array_atomic_type->value, 'Traversable')) {
                                    $generic_storage = $codebase->classlike_storage_provider->get($array_atomic_type->value);
                                    // The collection might be an iterator, in which case
                                    // we want to call the iterator function
                                    /** @psalm-suppress PossiblyUndefinedStringArrayOffset */
                                    if (!isset($generic_storage->template_extended_params['Traversable']) || $generic_storage->template_extended_params['Traversable']['TKey']->is_mixed() && $generic_storage->template_extended_params['Traversable']['TValue']->is_mixed()) {
                                        self::handle_iterable($statements_analyzer, $array_atomic_type, $fake_method_call, $codebase, $context, $key_type, $value_type, $has_valid_iterator, $invalid_iterator_types);
                                        continue;
                                    }
                                }
                                if ($array_atomic_type instanceof T_Iterable || $array_atomic_type instanceof T_Named_Object && ($array_atomic_type->value === 'Traversable' || $codebase->class_or_interface_exists($array_atomic_type->value) && $codebase->class_implements($array_atomic_type->value, 'Traversable'))) {
                                    self::get_key_value_params_for_traversable_object($array_atomic_type, $codebase, $key_type_part, $value_type_part);
                                }
                            }
                            if (!$key_type_part || !$value_type_part) {
                                break;
                            }
                            $key_type = Type::combine_union_types($key_type, $key_type_part);
                            $value_type = Type::combine_union_types($value_type, $value_type_part);
                        }
                    }
                } elseif ($iterator_atomic_type instanceof T_Generic_Object && strtolower($iterator_atomic_type->value) === 'generator') {
                    $type_params = $iterator_atomic_type->type_params;
                    if (isset($type_params[2]) && !$type_params[2]->is_nullable() && !$type_params[2]->is_void() && !$type_params[2]->is_mixed()) {
                        $invalid_iterator_types[] = $iterator_atomic_type->get_key();
                    } else {
                        $has_valid_iterator = true;
                    }
                    $iterator_value_type = self::get_fake_method_call_type($statements_analyzer, $foreach_expr, $context, 'current');
                    $iterator_key_type = self::get_fake_method_call_type($statements_analyzer, $foreach_expr, $context, 'key');
                    if ($iterator_value_type && !$iterator_value_type->is_mixed()) {
                        // remove null coming from current() to signify invalid iterations
                        // we're in a foreach context, so we know we're not going iterate past the end
                        if (isset($type_params[1]) && !$type_params[1]->is_nullable()) {
                            $iterator_value_type = $iterator_value_type->get_builder();
                            $iterator_value_type->remove_type('null');
                            $iterator_value_type = $iterator_value_type->freeze();
                        }
                        $value_type = Type::combine_union_types($value_type, $iterator_value_type);
                    }
                    if ($iterator_key_type && !$iterator_key_type->is_mixed()) {
                        // remove null coming from key() to signify invalid iterations
                        // we're in a foreach context, so we know we're not going iterate past the end
                        if (isset($type_params[0]) && !$type_params[0]->is_nullable()) {
                            $iterator_key_type = $iterator_key_type->get_builder();
                            $iterator_key_type->remove_type('null');
                            $iterator_key_type = $iterator_key_type->freeze();
                        }
                        $key_type = Type::combine_union_types($key_type, $iterator_key_type);
                    }
                } elseif ($codebase->class_implements($iterator_atomic_type->value, 'Iterator') || $codebase->interface_exists($iterator_atomic_type->value) && $codebase->interface_extends($iterator_atomic_type->value, 'Iterator')) {
                    $has_valid_iterator = true;
                    $iterator_value_type = self::get_fake_method_call_type($statements_analyzer, $foreach_expr, $context, 'current');
                    $iterator_key_type = self::get_fake_method_call_type($statements_analyzer, $foreach_expr, $context, 'key');
                    if ($iterator_value_type && !$iterator_value_type->is_mixed()) {
                        $value_type = Type::combine_union_types($value_type, $iterator_value_type);
                    }
                    if ($iterator_key_type && !$iterator_key_type->is_mixed()) {
                        $key_type = Type::combine_union_types($key_type, $iterator_key_type);
                    }
                }
                if (!$key_type && !$value_type) {
                    self::get_key_value_params_for_traversable_object($iterator_atomic_type, $codebase, $key_type, $value_type);
                }
                return;
            }
            if (!$codebase->classlikes->class_or_interface_exists($iterator_atomic_type->value)) {
                return;
            }
        }
    }
    public static function get_key_value_params_for_traversable_object(Atomic $iterator_atomic_type, Codebase $codebase, ?Union &$key_type, ?Union &$value_type): void
    {
        if ($iterator_atomic_type instanceof T_Iterable || $iterator_atomic_type instanceof T_Generic_Object && strtolower($iterator_atomic_type->value) === 'traversable') {
            assert(isset($iterator_atomic_type->type_params[1]));
            $value_type = Type::combine_union_types($value_type, $iterator_atomic_type->type_params[1]);
            $key_type = Type::combine_union_types($key_type, $iterator_atomic_type->type_params[0]);
            return;
        }
        if ($iterator_atomic_type instanceof T_Named_Object && ($codebase->class_implements($iterator_atomic_type->value, 'Traversable') || $codebase->interface_extends($iterator_atomic_type->value, 'Traversable'))) {
            $generic_storage = $codebase->classlike_storage_provider->get($iterator_atomic_type->value);
            if (!isset($generic_storage->template_extended_params['Traversable'])) {
                return;
            }
            if ($generic_storage->template_types || $iterator_atomic_type instanceof T_Generic_Object) {
                // if we're just being passed the non-generic class itself, assume
                // that it's inside the calling class
                $passed_type_params = $iterator_atomic_type instanceof T_Generic_Object ? $iterator_atomic_type->type_params : array_values(array_map(
                    /** @param array<string, Union> $arr */
                    static fn(array $arr): Union => $arr[$iterator_atomic_type->value] ?? Type::get_mixed(),
                    $generic_storage->template_types
                ));
            } else {
                $passed_type_params = null;
            }
            $key_type = self::get_extended_type('TKey', 'Traversable', $generic_storage->name, $generic_storage->template_extended_params, $generic_storage->template_types, $passed_type_params);
            $value_type = self::get_extended_type('TValue', 'Traversable', $generic_storage->name, $generic_storage->template_extended_params, $generic_storage->template_types, $passed_type_params);
            return;
        }
    }
    private static function get_fake_method_call_type(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr $foreach_expr, Context $context, string $method_name): ?Union
    {
        $old_data_provider = $statements_analyzer->node_data;
        $statements_analyzer->node_data = clone $statements_analyzer->node_data;
        $fake_method_call = new Virtual_Method_Call($foreach_expr, new Virtual_Identifier($method_name, $foreach_expr->get_attributes()));
        $suppressed_issues = $statements_analyzer->get_suppressed_issues();
        if (!in_array('PossiblyInvalidMethodCall', $suppressed_issues, true)) {
            $statements_analyzer->add_suppressed_issues(['PossiblyInvalidMethodCall']);
        }
        if (!in_array('PossiblyUndefinedMethod', $suppressed_issues, true)) {
            $statements_analyzer->add_suppressed_issues(['PossiblyUndefinedMethod']);
        }
        $was_inside_call = $context->inside_call;
        $context->inside_call = true;
        Method_Call_Analyzer::analyze($statements_analyzer, $fake_method_call, $context);
        $context->inside_call = $was_inside_call;
        if (!in_array('PossiblyInvalidMethodCall', $suppressed_issues, true)) {
            $statements_analyzer->remove_suppressed_issues(['PossiblyInvalidMethodCall']);
        }
        if (!in_array('PossiblyUndefinedMethod', $suppressed_issues, true)) {
            $statements_analyzer->remove_suppressed_issues(['PossiblyUndefinedMethod']);
        }
        $iterator_class_type = $statements_analyzer->node_data->get_type($fake_method_call) ?? null;
        $statements_analyzer->node_data = $old_data_provider;
        return $iterator_class_type;
    }
    /**
     * @param  array<string, array<string, Union>>  $template_extended_params
     * @param  array<string, array<string, Union>>  $class_template_types
     * @param  array<int, Union> $calling_type_params
     */
    private static function get_extended_type(string $template_name, string $template_class, string $calling_class, array $template_extended_params, ?array $class_template_types = null, ?array $calling_type_params = null): ?Union
    {
        if ($calling_class === $template_class) {
            if (isset($class_template_types[$template_name]) && $calling_type_params) {
                $offset = array_search($template_name, array_keys($class_template_types), true);
                if ($offset !== false && isset($calling_type_params[$offset])) {
                    return $calling_type_params[$offset];
                }
            }
            return null;
        }
        if (isset($template_extended_params[$template_class][$template_name])) {
            $extended_type = $template_extended_params[$template_class][$template_name];
            $return_type = null;
            foreach ($extended_type->get_atomic_types() as $extended_atomic_type) {
                if (!$extended_atomic_type instanceof T_Template_Param) {
                    $return_type = Type::combine_union_types($return_type, $extended_type);
                    continue;
                }
                $candidate_type = self::get_extended_type($extended_atomic_type->param_name, $extended_atomic_type->defining_class, $calling_class, $template_extended_params, $class_template_types, $calling_type_params);
                if ($candidate_type) {
                    $return_type = Type::combine_union_types($return_type, $candidate_type);
                }
            }
            if ($return_type) {
                return $return_type;
            }
        }
        return null;
    }
}