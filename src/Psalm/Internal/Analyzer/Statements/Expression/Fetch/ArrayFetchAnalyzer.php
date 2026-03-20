<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression\Fetch;

use Php_Parser;
use Php_Parser\Node\Expr;
use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Internal\Analyzer\Function_Like_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Array_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call\Method_Call_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Expression_Identifier;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Analyzer\Trait_Analyzer;
use Psalm\Internal\Codebase\Taint_Flow_Graph;
use Psalm\Internal\Codebase\Variable_Use_Graph;
use Psalm\Internal\Data_Flow\Data_Flow_Node;
use Psalm\Internal\Data_Flow\Taint_Source;
use Psalm\Internal\Type\Comparator\Atomic_Type_Comparator;
use Psalm\Internal\Type\Comparator\Type_Comparison_Result;
use Psalm\Internal\Type\Comparator\Union_Type_Comparator;
use Psalm\Internal\Type\Template_Inferred_Type_Replacer;
use Psalm\Internal\Type\Template_Result;
use Psalm\Internal\Type\Type_Combiner;
use Psalm\Internal\Type\Type_Expander;
use Psalm\Issue\Empty_Array_Access;
use Psalm\Issue\Invalid_Array_Access;
use Psalm\Issue\Invalid_Array_Assignment;
use Psalm\Issue\Invalid_Array_Offset;
use Psalm\Issue\Literal_Key_Unshaped_Array;
use Psalm\Issue\Mixed_Array_Access;
use Psalm\Issue\Mixed_Array_Assignment;
use Psalm\Issue\Mixed_Array_Offset;
use Psalm\Issue\Mixed_Array_Type_Coercion;
use Psalm\Issue\Mixed_String_Offset_Assignment;
use Psalm\Issue\Null_Array_Access;
use Psalm\Issue\Null_Array_Offset;
use Psalm\Issue\Possibly_Invalid_Array_Access;
use Psalm\Issue\Possibly_Invalid_Array_Assignment;
use Psalm\Issue\Possibly_Invalid_Array_Offset;
use Psalm\Issue\Possibly_Null_Array_Access;
use Psalm\Issue\Possibly_Null_Array_Assignment;
use Psalm\Issue\Possibly_Null_Array_Offset;
use Psalm\Issue\Possibly_Undefined_Array_Offset;
use Psalm\Issue\Possibly_Undefined_Int_Array_Offset;
use Psalm\Issue\Possibly_Undefined_String_Array_Offset;
use Psalm\Issue_Buffer;
use Psalm\Node\Expr\Virtual_Const_Fetch;
use Psalm\Node\Expr\Virtual_Method_Call;
use Psalm\Node\Virtual_Arg;
use Psalm\Node\Virtual_Identifier;
use Psalm\Node\Virtual_Name;
use Psalm\Plugin\Event_Handler\Event\Add_Remove_Taints_Event;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Array_Key;
use Psalm\Type\Atomic\T_Bool;
use Psalm\Type\Atomic\T_Class_Constant;
use Psalm\Type\Atomic\T_Class_String;
use Psalm\Type\Atomic\T_Class_String_Map;
use Psalm\Type\Atomic\T_False;
use Psalm\Type\Atomic\T_Float;
use Psalm\Type\Atomic\T_Int;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Atomic\T_Literal_Class_String;
use Psalm\Type\Atomic\T_Literal_Float;
use Psalm\Type\Atomic\T_Literal_Int;
use Psalm\Type\Atomic\T_Literal_String;
use Psalm\Type\Atomic\T_Mixed;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Never;
use Psalm\Type\Atomic\T_Non_Empty_Array;
use Psalm\Type\Atomic\T_Null;
use Psalm\Type\Atomic\T_Object;
use Psalm\Type\Atomic\T_Object_With_Properties;
use Psalm\Type\Atomic\T_Single_Letter;
use Psalm\Type\Atomic\T_String;
use Psalm\Type\Atomic\T_Template_Indexed_Access;
use Psalm\Type\Atomic\T_Template_Key_Of;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Atomic\T_Template_Param_Class;
use Psalm\Type\Atomic\T_True;
use Psalm\Type\Mutable_Union;
use Psalm\Type\Union;
use UnexpectedValueException;
use function array_diff;
use function array_keys;
use function array_map;
use function array_pop;
use function array_values;
use function count;
use function implode;
use function in_array;
use function is_int;
use function strlen;
use function strtolower;
/**
 * @internal
 */
final class Array_Fetch_Analyzer
{
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Array_Dim_Fetch $stmt, Context $context): bool
    {
        $extended_var_id = Expression_Identifier::get_extended_var_id($stmt->var, $statements_analyzer->get_fqcln(), $statements_analyzer);
        if ($stmt->dim) {
            $was_inside_general_use = $context->inside_general_use;
            $context->inside_general_use = true;
            $was_inside_unset = $context->inside_unset;
            $context->inside_unset = false;
            $was_inside_isset = $context->inside_isset;
            $context->inside_isset = false;
            if (Expression_Analyzer::analyze($statements_analyzer, $stmt->dim, $context) === false) {
                $context->inside_isset = $was_inside_isset;
                $context->inside_unset = $was_inside_unset;
                $context->inside_general_use = $was_inside_general_use;
                return false;
            }
            $context->inside_isset = $was_inside_isset;
            $context->inside_unset = $was_inside_unset;
            $context->inside_general_use = $was_inside_general_use;
        }
        $keyed_array_var_id = Expression_Identifier::get_extended_var_id($stmt, $statements_analyzer->get_fqcln(), $statements_analyzer);
        $dim_var_id = null;
        $new_offset_type = null;
        if ($stmt->dim) {
            $used_key_type = $statements_analyzer->node_data->get_type($stmt->dim) ?? Type::get_mixed();
            $dim_var_id = Expression_Identifier::get_extended_var_id($stmt->dim, $statements_analyzer->get_fqcln(), $statements_analyzer);
        } else {
            $used_key_type = Type::get_int();
        }
        if (Expression_Analyzer::analyze($statements_analyzer, $stmt->var, $context) === false) {
            return false;
        }
        $stmt_var_type = $statements_analyzer->node_data->get_type($stmt->var);
        $codebase = $statements_analyzer->get_codebase();
        if ($keyed_array_var_id !== null && $context->has_variable($keyed_array_var_id) && !$context->vars_in_scope[$keyed_array_var_id]->possibly_undefined && $stmt_var_type && !$stmt_var_type->has_class_string_map()) {
            $stmt_type = $context->vars_in_scope[$keyed_array_var_id];
            self::taint_array_fetch($statements_analyzer, $stmt->var, $keyed_array_var_id, $stmt_type, $used_key_type, $context);
            if ($stmt->dim && $statements_analyzer->node_data->get_type($stmt->dim)) {
                $statements_analyzer->node_data->set_type($stmt->dim, $used_key_type);
            }
            $statements_analyzer->node_data->set_type($stmt, $stmt_type);
            return true;
        }
        $can_store_result = false;
        if ($stmt_var_type) {
            if ($stmt_var_type->is_null()) {
                if (!$context->inside_isset) {
                    Issue_Buffer::maybe_add(new Null_Array_Access('Cannot access array value on null variable ' . $extended_var_id, new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
                }
                $stmt_type = $statements_analyzer->node_data->get_type($stmt);
                $statements_analyzer->node_data->set_type($stmt, Type::combine_union_types($stmt_type, Type::get_null()));
                return true;
            }
            $stmt_type = self::get_array_access_type_given_offset($statements_analyzer, $stmt, $stmt_var_type, $used_key_type, false, $extended_var_id, $context);
            if ($stmt->dim && $stmt_var_type->has_array()) {
                $array_type = $stmt_var_type->get_array();
                if ($array_type instanceof T_Class_String_Map) {
                    $array_value_type = Type::get_mixed();
                } elseif ($array_type instanceof T_Array) {
                    $array_value_type = $array_type->type_params[1];
                } else {
                    $array_value_type = $array_type->get_generic_value_type();
                }
                if ($context->inside_assignment || !$array_value_type->is_mixed()) {
                    $can_store_result = true;
                }
            }
            if ($context->inside_isset && !$stmt_type->has_mixed()) {
                $stmt_type = Type::combine_union_types($stmt_type, Type::get_null());
            }
            $statements_analyzer->node_data->set_type($stmt, $stmt_type);
            if ($context->inside_isset && $stmt->dim && ($stmt_dim_type = $statements_analyzer->node_data->get_type($stmt->dim)) && $stmt_var_type->has_array() && ($stmt->var instanceof Php_Parser\Node\Expr\Class_Const_Fetch || $stmt->var instanceof Php_Parser\Node\Expr\Const_Fetch)) {
                /**
                 * @var TArray|TKeyedArray
                 */
                $array_type = $stmt_var_type->get_array();
                if ($array_type instanceof T_Array) {
                    $const_array_key_type = $array_type->type_params[0];
                } else {
                    $const_array_key_type = $array_type->get_generic_key_type();
                }
                if ($dim_var_id && !$const_array_key_type->has_mixed() && !$stmt_dim_type->has_mixed()) {
                    $new_offset_type = $stmt_dim_type->get_builder();
                    $const_array_key_atomic_types = $const_array_key_type->get_atomic_types();
                    foreach ($new_offset_type->get_atomic_types() as $offset_key => $offset_atomic_type) {
                        if ($offset_atomic_type instanceof T_String || $offset_atomic_type instanceof T_Int) {
                            if (!isset($const_array_key_atomic_types[$offset_key]) && !Union_Type_Comparator::is_contained_by($codebase, new Union([$offset_atomic_type]), $const_array_key_type)) {
                                $new_offset_type->remove_type($offset_key);
                            }
                        } elseif (!Union_Type_Comparator::is_contained_by($codebase, $const_array_key_type, new Union([$offset_atomic_type]))) {
                            $new_offset_type->remove_type($offset_key);
                        }
                    }
                    $new_offset_type = $new_offset_type->freeze();
                }
            }
        }
        if ($keyed_array_var_id !== null && $context->has_variable($keyed_array_var_id) && (!($stmt_type = $statements_analyzer->node_data->get_type($stmt)) || $stmt_type->is_vanilla_mixed())) {
            $statements_analyzer->node_data->set_type($stmt, $context->vars_in_scope[$keyed_array_var_id]);
        }
        if (!$stmt_type = $statements_analyzer->node_data->get_type($stmt)) {
            $stmt_type = Type::get_mixed();
        } else {
            if ($stmt_type->possibly_undefined && !$context->inside_isset && !$context->inside_unset && ($stmt_var_type && !$stmt_var_type->has_mixed())) {
                if (Issue_Buffer::accepts(new Possibly_Undefined_Array_Offset('Possibly undefined array key ' . $keyed_array_var_id . ' on ' . $stmt_var_type->get_id(), new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues())) {
                    $stmt_type = $stmt_type->get_builder()->add_type(new T_Null())->freeze();
                }
            } elseif ($stmt_type->possibly_undefined) {
                $stmt_type = $stmt_type->get_builder()->add_type(new T_Null())->freeze();
            }
            $stmt_type = $stmt_type->set_possibly_undefined(false);
        }
        if ($context->inside_isset && $dim_var_id && $new_offset_type && !$new_offset_type->is_union_empty()) {
            $context->vars_in_scope[$dim_var_id] = $new_offset_type;
        }
        self::taint_array_fetch($statements_analyzer, $stmt->var, $keyed_array_var_id, $stmt_type, $used_key_type, $context);
        $statements_analyzer->node_data->set_type($stmt, $stmt_type);
        if ($stmt->dim && $statements_analyzer->node_data->get_type($stmt->dim)) {
            $statements_analyzer->node_data->set_type($stmt->dim, $used_key_type);
        }
        if ($keyed_array_var_id && !$context->inside_isset && $can_store_result) {
            $context->vars_in_scope[$keyed_array_var_id] = $stmt_type;
            $context->vars_possibly_in_scope[$keyed_array_var_id] = true;
            // reference the variable too
            $context->has_variable($keyed_array_var_id);
        }
        return true;
    }
    /**
     * Used to create a path between a variable $foo and $foo["a"]
     */
    public static function taint_array_fetch(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr $var, ?string $keyed_array_var_id, Union &$stmt_type, Union &$offset_type, ?Context $context = null): void
    {
        if ($statements_analyzer->data_flow_graph && ($stmt_var_type = $statements_analyzer->node_data->get_type($var)) && $stmt_var_type->parent_nodes) {
            if ($statements_analyzer->data_flow_graph instanceof Taint_Flow_Graph && in_array('TaintedInput', $statements_analyzer->get_suppressed_issues())) {
                $statements_analyzer->node_data->set_type($var, $stmt_var_type->set_parent_nodes([]));
                return;
            }
            $var_location = new Code_Location($statements_analyzer->get_source(), $var);
            $new_parent_node = Data_Flow_Node::get_for_assignment($keyed_array_var_id ?: 'arrayvalue-fetch', $var_location);
            $added_taints = [];
            $removed_taints = [];
            if ($context) {
                $codebase = $statements_analyzer->get_codebase();
                $event = new Add_Remove_Taints_Event($var, $context, $statements_analyzer, $codebase);
                $added_taints = $codebase->config->event_dispatcher->dispatch_add_taints($event);
                $removed_taints = $codebase->config->event_dispatcher->dispatch_remove_taints($event);
                $taints = array_diff($added_taints, $removed_taints);
                if ($taints !== [] && $statements_analyzer->data_flow_graph instanceof Taint_Flow_Graph) {
                    $taint_source = Taint_Source::from_node($new_parent_node);
                    $taint_source->taints = $taints;
                    $statements_analyzer->data_flow_graph->add_source($taint_source);
                }
            }
            $array_key_node = null;
            $statements_analyzer->data_flow_graph->add_node($new_parent_node);
            $dim_value = $offset_type->is_single_string_literal() ? $offset_type->get_single_string_literal()->value : ($offset_type->is_single_int_literal() ? $offset_type->get_single_int_literal()->value : null);
            if ($keyed_array_var_id === null && $dim_value === null) {
                $array_key_node = Data_Flow_Node::get_for_assignment('arraykey-fetch', $var_location);
                $statements_analyzer->data_flow_graph->add_node($array_key_node);
            }
            foreach ($stmt_var_type->parent_nodes as $parent_node) {
                $statements_analyzer->data_flow_graph->add_path($parent_node, $new_parent_node, 'arrayvalue-fetch' . ($dim_value !== null ? '-\'' . $dim_value . '\'' : ''), $added_taints, $removed_taints);
                if ($stmt_type->by_ref) {
                    $statements_analyzer->data_flow_graph->add_path($new_parent_node, $parent_node, 'arrayvalue-assignment' . ($dim_value !== null ? '-\'' . $dim_value . '\'' : ''), $added_taints, $removed_taints);
                }
                if ($array_key_node) {
                    $statements_analyzer->data_flow_graph->add_path($parent_node, $array_key_node, 'arraykey-fetch', $added_taints, $removed_taints);
                }
            }
            $stmt_type = $stmt_type->set_parent_nodes([$new_parent_node->id => $new_parent_node]);
            if ($array_key_node) {
                $offset_type = $offset_type->set_parent_nodes([$array_key_node->id => $array_key_node]);
            }
        }
    }
    /**
     * @psalm-suppress ComplexMethod to be refactored.
     * Good type/bad type behaviour could be mutualised with ArrayAnalyzer
     */
    public static function get_array_access_type_given_offset(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Array_Dim_Fetch $stmt, Union &$array_type, Union &$offset_type_original, bool $in_assignment, ?string $extended_var_id, Context $context, ?Php_Parser\Node\Expr $assign_value = null, ?Union $replacement_type = null): Union
    {
        $offset_type = $offset_type_original->get_builder();
        $codebase = $statements_analyzer->get_codebase();
        $has_array_access = false;
        $non_array_types = [];
        $has_valid_expected_offset = false;
        $expected_offset_types = [];
        $key_values = [];
        if ($codebase->store_node_types && !$context->collect_initializations && !$context->collect_mutations) {
            $codebase->analyzer->add_node_type($statements_analyzer->get_file_path(), $stmt->var, $array_type->get_id());
        }
        if ($stmt->dim instanceof Php_Parser\Node\Scalar\String_) {
            $value_type = Type::get_atomic_string_from_literal($stmt->dim->value);
            if ($value_type instanceof T_Literal_String) {
                $key_values[] = $value_type;
            }
        } elseif ($stmt->dim instanceof Php_Parser\Node\Scalar\Int_) {
            $key_values[] = new T_Literal_Int($stmt->dim->value);
        } elseif ($stmt->dim && $stmt_dim_type = $statements_analyzer->node_data->get_type($stmt->dim)) {
            $string_literals = $stmt_dim_type->get_literal_strings();
            $int_literals = $stmt_dim_type->get_literal_ints();
            $all_atomic_types = $stmt_dim_type->get_atomic_types();
            if (count($string_literals) + count($int_literals) === count($all_atomic_types)) {
                foreach ($string_literals as $string_literal) {
                    $key_values[] = $string_literal;
                }
                foreach ($int_literals as $int_literal) {
                    $key_values[] = $int_literal;
                }
            }
        }
        $array_access_type = null;
        if ($offset_type->is_null()) {
            Issue_Buffer::maybe_add(new Null_Array_Offset('Cannot access value on variable ' . $extended_var_id . ' using null offset', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
            if ($in_assignment) {
                $offset_type->remove_type('null');
                $offset_type->add_type(Type::get_atomic_string_from_literal(''));
            }
        }
        if ($codebase->literal_array_key_check && !$in_assignment) {
            self::validate_array_offset($statements_analyzer, $stmt, $array_type, $offset_type);
        }
        if ($offset_type->is_nullable()) {
            if (!$offset_type->ignore_nullable_issues) {
                Issue_Buffer::maybe_add(new Possibly_Null_Array_Offset('Cannot access value on variable ' . $extended_var_id . ' using possibly null offset ' . $offset_type, new Code_Location($statements_analyzer->get_source(), $stmt->var)), $statements_analyzer->get_suppressed_issues());
            }
            if ($in_assignment) {
                $offset_type->remove_type('null');
                if (!$offset_type->ignore_nullable_issues) {
                    $offset_type->add_type(Type::get_atomic_string_from_literal(''));
                }
            }
        }
        if ($array_type->is_array()) {
            $has_valid_absolute_offset = self::check_array_offset_type($offset_type, $offset_type->get_atomic_types(), $codebase);
            if ($has_valid_absolute_offset === false) {
                //we didn't find a single type that could be valid
                $expected_offset_types[] = 'array-key';
            }
        } else {
            //on not-arrays, the type is considered valid
            $has_valid_absolute_offset = true;
        }
        $types = $array_type->get_atomic_types();
        $changed = false;
        foreach ($types as $type_string => $type) {
            $original_type_real = $type;
            $original_type = $type;
            if ($type instanceof T_Mixed || $type instanceof T_Template_Param || $type instanceof T_Never) {
                if (!$type instanceof T_Template_Param || $type->as->is_mixed() || !$type->as->is_single()) {
                    $array_access_type = self::handle_mixed_array_access($context, $statements_analyzer, $codebase, $in_assignment, $extended_var_id, $stmt, $array_access_type, $type);
                    $has_valid_expected_offset = true;
                    continue;
                }
                $type = $type->as->get_single_atomic();
                $original_type = $type;
            }
            if ($type instanceof T_Null) {
                if ($array_type->ignore_nullable_issues) {
                    continue;
                }
                if ($in_assignment) {
                    if ($replacement_type) {
                        $array_access_type = Type::combine_union_types($array_access_type, $replacement_type);
                    } else {
                        Issue_Buffer::maybe_add(new Possibly_Null_Array_Assignment('Cannot access array value on possibly null variable ' . $extended_var_id . ' of type ' . $array_type, new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
                        $array_access_type = new Union([new T_Never()]);
                    }
                } else {
                    if (!$context->inside_isset && !Method_Call_Analyzer::has_nullsafe($stmt->var)) {
                        Issue_Buffer::maybe_add(new Possibly_Null_Array_Access('Cannot access array value on possibly null variable ' . $extended_var_id . ' of type ' . $array_type, new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
                    }
                    $array_access_type = Type::combine_union_types($array_access_type, Type::get_null());
                }
                continue;
            }
            if ($type instanceof T_Array || $type instanceof T_Keyed_Array || $type instanceof T_Class_String_Map) {
                self::handle_array_access_on_array($in_assignment, $type, $key_values, $array_type->has_mixed(), $stmt, $replacement_type, $offset_type, $original_type_real, $codebase, $extended_var_id, $context, $statements_analyzer, $expected_offset_types, $array_access_type, $has_array_access, $has_valid_expected_offset);
                if ($type !== $original_type) {
                    $changed = true;
                    unset($types[$type_string]);
                    $types[$type->get_key()] = $type;
                }
                continue;
            }
            if ($type instanceof T_String) {
                self::handle_array_access_on_string($statements_analyzer, $codebase, $stmt, $in_assignment, $context, $replacement_type, $type, $offset_type, $expected_offset_types, $array_access_type, $has_valid_expected_offset);
                continue;
            }
            if (!$context->collect_initializations && !$context->collect_mutations && $statements_analyzer->get_file_path() === $statements_analyzer->get_root_file_path() && (!($parent_source = $statements_analyzer->get_source()) instanceof Function_Like_Analyzer || !$parent_source->get_source() instanceof Trait_Analyzer)) {
                $codebase->analyzer->increment_non_mixed_count($statements_analyzer->get_file_path());
            }
            if ($type instanceof T_False && $array_type->ignore_falsable_issues) {
                continue;
            }
            if ($type instanceof T_Named_Object) {
                self::handle_array_access_on_named_object($statements_analyzer, $stmt, $type, $context, $in_assignment, $assign_value, $array_access_type, $has_array_access);
            } elseif (!$array_type->has_mixed()) {
                $non_array_types[] = (string) $type;
            }
        }
        if ($changed) {
            $array_type = $array_type->get_builder()->set_types($types)->freeze();
        }
        if ($non_array_types) {
            if ($has_array_access) {
                if ($in_assignment) {
                    Issue_Buffer::maybe_add(new Possibly_Invalid_Array_Assignment('Cannot access array value on non-array variable ' . $extended_var_id . ' of type ' . $non_array_types[0], new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
                } elseif (!$context->inside_isset) {
                    Issue_Buffer::maybe_add(new Possibly_Invalid_Array_Access('Cannot access array value on non-array variable ' . $extended_var_id . ' of type ' . $non_array_types[0], new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
                }
            } else {
                if ($in_assignment) {
                    Issue_Buffer::maybe_add(new Invalid_Array_Assignment('Cannot access array value on non-array variable ' . $extended_var_id . ' of type ' . $non_array_types[0], new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
                } else {
                    Issue_Buffer::maybe_add(new Invalid_Array_Access('Cannot access array value on non-array variable ' . $extended_var_id . ' of type ' . $non_array_types[0], new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
                }
                $array_access_type = Type::get_mixed();
            }
        }
        if ($offset_type->has_mixed()) {
            if (!$context->collect_initializations && !$context->collect_mutations && $statements_analyzer->get_file_path() === $statements_analyzer->get_root_file_path() && (!($parent_source = $statements_analyzer->get_source()) instanceof Function_Like_Analyzer || !$parent_source->get_source() instanceof Trait_Analyzer)) {
                $codebase->analyzer->increment_mixed_count($statements_analyzer->get_file_path());
            }
            Issue_Buffer::maybe_add(new Mixed_Array_Offset('Cannot access value on variable ' . $extended_var_id . ' using mixed offset', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
        } else {
            if (!$context->collect_initializations && !$context->collect_mutations && $statements_analyzer->get_file_path() === $statements_analyzer->get_root_file_path() && (!($parent_source = $statements_analyzer->get_source()) instanceof Function_Like_Analyzer || !$parent_source->get_source() instanceof Trait_Analyzer)) {
                $codebase->analyzer->increment_non_mixed_count($statements_analyzer->get_file_path());
            }
            if ($expected_offset_types) {
                $invalid_offset_type = $expected_offset_types[0];
                $used_offset = 'using a ' . $offset_type->get_id() . ' offset';
                if ($key_values) {
                    $used_offset = "using offset value of '" . implode('|', array_map(static fn(Atomic $atomic_type): int|string => $atomic_type->value, $key_values)) . "'";
                }
                if ($has_valid_expected_offset && $has_valid_absolute_offset && $context->inside_isset) {
                    // do nothing
                } elseif ($has_valid_expected_offset && $has_valid_absolute_offset) {
                    if (!$context->inside_unset) {
                        Issue_Buffer::maybe_add(new Possibly_Invalid_Array_Offset('Cannot access value on variable ' . $extended_var_id . ' ' . $used_offset . ', expecting ' . $invalid_offset_type, new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
                    }
                } else {
                    $good_types = [];
                    $bad_types = [];
                    foreach ($offset_type->get_atomic_types() as $atomic_key_type) {
                        if (!$atomic_key_type instanceof T_String && !$atomic_key_type instanceof T_Int && !$atomic_key_type instanceof T_Array_Key && !$atomic_key_type instanceof T_Mixed && !$atomic_key_type instanceof T_Template_Param && !($atomic_key_type instanceof T_Object_With_Properties && isset($atomic_key_type->methods['__tostring']))) {
                            $bad_types[] = $atomic_key_type;
                            if ($atomic_key_type instanceof T_False) {
                                $good_types[] = new T_Literal_Int(0);
                            } elseif ($atomic_key_type instanceof T_True) {
                                $good_types[] = new T_Literal_Int(1);
                            } elseif ($atomic_key_type instanceof T_Bool) {
                                $good_types[] = new T_Literal_Int(0);
                                $good_types[] = new T_Literal_Int(1);
                            } elseif ($atomic_key_type instanceof T_Literal_Float) {
                                $good_types[] = new T_Literal_Int((int) $atomic_key_type->value);
                            } elseif ($atomic_key_type instanceof T_Float) {
                                $good_types[] = new T_Int();
                            } else {
                                $good_types[] = new T_Array_Key();
                            }
                        }
                    }
                    if ($bad_types && $good_types) {
                        $offset_type->substitute(Type_Combiner::combine($bad_types, $codebase), Type_Combiner::combine($good_types, $codebase));
                    }
                    Issue_Buffer::maybe_add(new Invalid_Array_Offset('Cannot access value on variable ' . $extended_var_id . ' ' . $used_offset . ', expecting ' . $invalid_offset_type, new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
                }
            }
        }
        $offset_type_original = $offset_type->freeze();
        if ($array_access_type === null) {
            // shouldn’t happen, but don’t crash
            return Type::get_mixed();
        }
        if ($array_type->by_ref) {
            return $array_access_type->set_by_ref(true);
        }
        return $array_access_type;
    }
    private static function check_literal_int_array_offset(Mutable_Union $offset_type, Union $expected_offset_type, ?string $extended_var_id, Php_Parser\Node\Expr\Array_Dim_Fetch $stmt, Context $context, Statements_Analyzer $statements_analyzer): void
    {
        if ($context->inside_isset || $context->inside_unset) {
            return;
        }
        if ($offset_type->has_literal_int()) {
            $found_match = false;
            foreach ($offset_type->get_atomic_types() as $offset_type_part) {
                if ($extended_var_id && $offset_type_part instanceof T_Literal_Int && isset($context->vars_in_scope[$extended_var_id . '[' . $offset_type_part->value . ']']) && !$context->vars_in_scope[$extended_var_id . '[' . $offset_type_part->value . ']']->possibly_undefined) {
                    $found_match = true;
                    break;
                }
            }
            if (!$found_match) {
                Issue_Buffer::maybe_add(new Possibly_Undefined_Int_Array_Offset('Possibly undefined array offset \'' . $offset_type->get_id() . '\' ' . 'is risky given expected type \'' . $expected_offset_type->get_id() . '\'.' . ' Consider using isset beforehand.', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
            }
        }
    }
    private static function check_literal_string_array_offset(Mutable_Union $offset_type, Union $expected_offset_type, ?string $extended_var_id, Php_Parser\Node\Expr\Array_Dim_Fetch $stmt, Context $context, Statements_Analyzer $statements_analyzer): void
    {
        if ($context->inside_isset || $context->inside_unset) {
            return;
        }
        if ($offset_type->has_literal_string() && !$expected_offset_type->has_literal_class_string()) {
            $found_match = false;
            foreach ($offset_type->get_atomic_types() as $offset_type_part) {
                if ($extended_var_id === null) {
                    continue;
                }
                if (!$offset_type_part instanceof T_Literal_String) {
                    continue;
                }
                $string_to_int = Array_Analyzer::get_literal_array_key_int($offset_type_part->value);
                $literal_access = $string_to_int === false ? '\'' . $offset_type_part->value . '\'' : $string_to_int;
                if (isset($context->vars_in_scope[$extended_var_id . '[' . $literal_access . ']']) && !$context->vars_in_scope[$extended_var_id . '[' . $literal_access . ']']->possibly_undefined) {
                    $found_match = true;
                    break;
                }
            }
            if (!$found_match) {
                Issue_Buffer::maybe_add(new Possibly_Undefined_String_Array_Offset('Possibly undefined array offset \'' . $offset_type->get_id() . '\' ' . 'is risky given expected type \'' . $expected_offset_type->get_id() . '\'.' . ' Consider using isset beforehand.', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
            }
        }
    }
    public static function replace_offset_type_with_ints(Union $offset_type): Union
    {
        $offset_type = $offset_type->get_builder();
        $offset_types = $offset_type->get_atomic_types();
        foreach ($offset_types as $key => $offset_type_part) {
            if ($offset_type_part instanceof T_Literal_String) {
                $string_to_int = Array_Analyzer::get_literal_array_key_int($offset_type_part->value);
                if ($string_to_int !== false) {
                    $offset_type->add_type(new T_Literal_Int($string_to_int));
                    $offset_type->remove_type($key);
                }
            } elseif ($offset_type_part instanceof T_Bool) {
                if ($offset_type_part instanceof T_False) {
                    if (!$offset_type->ignore_falsable_issues) {
                        $offset_type->add_type(new T_Literal_Int(0));
                        $offset_type->remove_type($key);
                    }
                } elseif ($offset_type_part instanceof T_True) {
                    $offset_type->add_type(new T_Literal_Int(1));
                    $offset_type->remove_type($key);
                } else {
                    $offset_type->add_type(new T_Literal_Int(0));
                    $offset_type->add_type(new T_Literal_Int(1));
                    $offset_type->remove_type($key);
                }
            }
        }
        return $offset_type->freeze();
    }
    /**
     * @param  TMixed|TTemplateParam|TNever $type
     */
    public static function handle_mixed_array_access(Context $context, Statements_Analyzer $statements_analyzer, Codebase $codebase, bool $in_assignment, ?string $extended_var_id, Php_Parser\Node\Expr\Array_Dim_Fetch $stmt, ?Union $array_access_type, Atomic $type): Union
    {
        if (!$context->collect_initializations && !$context->collect_mutations && $statements_analyzer->get_file_path() === $statements_analyzer->get_root_file_path() && (!($parent_source = $statements_analyzer->get_source()) instanceof Function_Like_Analyzer || !$parent_source->get_source() instanceof Trait_Analyzer)) {
            $codebase->analyzer->increment_mixed_count($statements_analyzer->get_file_path());
        }
        if (!$context->inside_isset) {
            if ($in_assignment) {
                Issue_Buffer::maybe_add(new Mixed_Array_Assignment('Cannot access array value on mixed variable ' . $extended_var_id, new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
            } else {
                Issue_Buffer::maybe_add(new Mixed_Array_Access('Cannot access array value on mixed variable ' . $extended_var_id, new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
            }
        }
        if (($data_flow_graph = $statements_analyzer->data_flow_graph) && $data_flow_graph instanceof Variable_Use_Graph && $stmt_var_type = $statements_analyzer->node_data->get_type($stmt->var)) {
            if ($stmt_var_type->parent_nodes) {
                $var_location = new Code_Location($statements_analyzer->get_source(), $stmt->var);
                $new_parent_node = Data_Flow_Node::get_for_assignment('mixed-var-array-access', $var_location);
                $data_flow_graph->add_node($new_parent_node);
                foreach ($stmt_var_type->parent_nodes as $parent_node) {
                    $data_flow_graph->add_path($parent_node, $new_parent_node, '=');
                    $data_flow_graph->add_path($parent_node, new Data_Flow_Node('variable-use', 'variable use', null), 'variable-use');
                }
                $statements_analyzer->node_data->set_type($stmt->var, $stmt_var_type->set_parent_nodes([$new_parent_node->id => $new_parent_node]));
            }
        }
        return Type::combine_union_types($array_access_type, Type::get_mixed($type instanceof T_Never));
    }
    /**
     * @param list<string> $expected_offset_types
     * @param TArray|TKeyedArray|TClassStringMap $type
     * @param-out TArray|TKeyedArray|TClassStringMap $type
     * @param list<TLiteralInt|TLiteralString> $key_values
     * @psalm-suppress ConflictingReferenceConstraint Ignore
     */
    private static function handle_array_access_on_array(bool $in_assignment, Atomic &$type, array &$key_values, bool $has_mixed, Php_Parser\Node\Expr\Array_Dim_Fetch $stmt, ?Union $replacement_type, Mutable_Union $offset_type, Atomic $original_type, Codebase $codebase, ?string $extended_var_id, Context $context, Statements_Analyzer $statements_analyzer, array &$expected_offset_types, ?Union &$array_access_type, bool &$has_array_access, bool &$has_valid_offset): void
    {
        $has_array_access = true;
        if ($in_assignment) {
            if ($type instanceof T_Array) {
                $from_empty_array = $type->is_empty_array();
                if (count($key_values) === 1) {
                    $single_atomic = $key_values[0];
                    $from_mixed_array = $type->type_params[1]->is_mixed();
                    // ok, type becomes a TKeyedArray
                    $type = new T_Keyed_Array([$single_atomic->value => $from_mixed_array ? Type::get_mixed() : Type::get_never()], $single_atomic instanceof T_Literal_Class_String ? [$single_atomic->value => true] : null, $from_empty_array ? null : $type->type_params);
                } elseif (!$stmt->dim && $from_empty_array && $replacement_type) {
                    $type = new T_Keyed_Array([$replacement_type], null, null, true);
                    return;
                }
            } elseif ($type instanceof T_Keyed_Array && $type->fallback_params !== null && $type->fallback_params[1]->is_mixed() && count($key_values) === 1) {
                $properties = $type->properties;
                $properties[$key_values[0]->value] = Type::get_mixed();
                $type = $type->set_properties($properties);
            }
        }
        $offset_type = self::replace_offset_type_with_ints($offset_type->freeze())->get_builder();
        if ($type instanceof T_Keyed_Array && $type->is_list && ($in_assignment && $stmt->dim || $original_type instanceof T_Template_Param || !$offset_type->is_int())) {
            $temp = $type->get_generic_array_type();
            self::handle_array_access_on_t_array($statements_analyzer, $codebase, $context, $stmt, $has_mixed, $extended_var_id, $temp, $offset_type, $in_assignment, $expected_offset_types, $array_access_type, $original_type, $has_valid_offset);
        } elseif ($type instanceof T_Array) {
            self::handle_array_access_on_t_array($statements_analyzer, $codebase, $context, $stmt, $has_mixed, $extended_var_id, $type, $offset_type, $in_assignment, $expected_offset_types, $array_access_type, $original_type, $has_valid_offset);
        } elseif ($type instanceof T_Class_String_Map) {
            self::handle_array_access_on_class_string_map($codebase, $type, $offset_type, $replacement_type, $array_access_type);
        } else {
            self::handle_array_access_on_keyed_array($statements_analyzer, $codebase, $key_values, $replacement_type, $array_access_type, $in_assignment, $stmt, $offset_type, $extended_var_id, $context, $type, $has_mixed, $expected_offset_types, $has_valid_offset);
        }
        if ($context->inside_isset) {
            $offset_type->ignore_isset = true;
        }
    }
    /**
     * @param list<string> $expected_offset_types
     * @param-out TArray $type
     */
    private static function handle_array_access_on_t_array(Statements_Analyzer $statements_analyzer, Codebase $codebase, Context $context, Php_Parser\Node\Expr\Array_Dim_Fetch $stmt, bool $has_mixed, ?string $extended_var_id, T_Array &$type, Mutable_Union $offset_type, bool $in_assignment, array &$expected_offset_types, ?Union &$array_access_type, Atomic $original_type, bool &$has_valid_offset): void
    {
        // if we're assigning to an empty array with a key offset, refashion that array
        if ($in_assignment) {
            if ($type->is_empty_array()) {
                $type = $type->set_type_params([$offset_type->is_mixed() ? Type::get_array_key() : $offset_type->freeze(), $type->type_params[1]]);
            }
        } elseif (!$type->is_empty_array()) {
            $expected_offset_type = $type->type_params[0]->has_mixed() ? new Union([new T_Array_Key()]) : $type->type_params[0];
            $templated_offset_type = null;
            foreach ($offset_type->get_atomic_types() as $offset_atomic_type) {
                if ($offset_atomic_type instanceof T_Template_Param) {
                    $templated_offset_type = $offset_atomic_type;
                }
            }
            $union_comparison_results = new Type_Comparison_Result();
            if ($original_type instanceof T_Template_Param && $templated_offset_type) {
                foreach ($templated_offset_type->as->get_atomic_types() as $offset_as) {
                    if ($offset_as instanceof T_Template_Key_Of && $offset_as->param_name === $original_type->param_name && $offset_as->defining_class === $original_type->defining_class) {
                        $type = $type->set_type_params([$type->type_params[0], new Union([new T_Template_Indexed_Access($offset_as->param_name, $templated_offset_type->param_name, $offset_as->defining_class)])]);
                        $has_valid_offset = true;
                    }
                }
            } else {
                $offset_type_contained_by_expected = Union_Type_Comparator::is_contained_by($codebase, $offset_type->freeze(), $expected_offset_type, true, $offset_type->ignore_falsable_issues, $union_comparison_results);
                if ($codebase->config->ensure_array_string_offsets_exist && $offset_type_contained_by_expected) {
                    //we already know we found a match, so if the array is non-empty and the key is a literal,
                    //then no need to check for PossiblyUndefinedStringArrayOffset
                    if (!$type instanceof T_Non_Empty_Array || !$type->type_params[0]->is_single_string_literal()) {
                        self::check_literal_string_array_offset($offset_type, $expected_offset_type, $extended_var_id, $stmt, $context, $statements_analyzer);
                    }
                }
                if ($codebase->config->ensure_array_int_offsets_exist && $offset_type_contained_by_expected) {
                    self::check_literal_int_array_offset($offset_type, $expected_offset_type, $extended_var_id, $stmt, $context, $statements_analyzer);
                }
                if (!$offset_type_contained_by_expected && !$union_comparison_results->type_coerced_from_scalar || $union_comparison_results->to_string_cast) {
                    if ($union_comparison_results->type_coerced_from_mixed && !$offset_type->is_mixed()) {
                        Issue_Buffer::maybe_add(new Mixed_Array_Type_Coercion('Coercion from array offset type \'' . $offset_type->get_id() . '\' ' . 'to the expected type \'' . $expected_offset_type->get_id() . '\'', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
                    } else {
                        $expected_offset_types[] = $expected_offset_type->get_id();
                    }
                    if (Union_Type_Comparator::can_expression_types_be_identical($codebase, $offset_type->freeze(), $expected_offset_type)) {
                        $has_valid_offset = true;
                    }
                } else {
                    $has_valid_offset = true;
                }
            }
        }
        if (!$stmt->dim) {
            if ($type instanceof T_Non_Empty_Array) {
                if ($type->count !== null) {
                    $type = $type->set_count($type->count + 1);
                }
            } else {
                $type = new T_Non_Empty_Array($type->type_params, null, null, 'non-empty-array', $type->from_docblock);
            }
        }
        $array_access_type = Type::combine_union_types($array_access_type, $type->type_params[1]);
        if ($array_access_type->is_never() && !$has_mixed && !$in_assignment && !$context->inside_isset) {
            Issue_Buffer::maybe_add(new Empty_Array_Access('Cannot access value on empty array variable ' . $extended_var_id, new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
            if (!Issue_Buffer::is_recording()) {
                $array_access_type = Type::get_mixed(true);
            }
        }
    }
    private static function handle_array_access_on_class_string_map(Codebase $codebase, T_Class_String_Map &$type, Mutable_Union $offset_type, ?Union $replacement_type, ?Union &$array_access_type): void
    {
        $offset_type_parts = array_values($offset_type->get_atomic_types());
        foreach ($offset_type_parts as $offset_type_part) {
            if ($offset_type_part instanceof T_Class_String) {
                if ($offset_type_part instanceof T_Template_Param_Class) {
                    $template_result_get = new Template_Result([], [$type->param_name => ['class-string-map' => new Union([new T_Template_Param($offset_type_part->param_name, $offset_type_part->as_type ? new Union([$offset_type_part->as_type]) : Type::get_object(), $offset_type_part->defining_class)])]]);
                    $template_result_set = new Template_Result([], [$offset_type_part->param_name => [$offset_type_part->defining_class => new Union([new T_Template_Param($type->param_name, $type->as_type ? new Union([$type->as_type]) : Type::get_object(), 'class-string-map')])]]);
                } else {
                    $template_result_get = new Template_Result([], [$type->param_name => ['class-string-map' => new Union([$offset_type_part->as_type ?: new T_Object()])]]);
                    $template_result_set = new Template_Result([], []);
                }
                $expected_value_param_get = Template_Inferred_Type_Replacer::replace($type->value_param, $template_result_get, $codebase);
                if ($replacement_type) {
                    $replacement_type = Template_Inferred_Type_Replacer::replace($replacement_type, $template_result_set, $codebase);
                    $type = new T_Class_String_Map($type->param_name, $type->as_type, Type::combine_union_types($replacement_type, $type->value_param, $codebase));
                }
                $array_access_type = Type::combine_union_types($array_access_type, $expected_value_param_get, $codebase);
            }
        }
    }
    /**
     * @param list<string> $expected_offset_types
     * @param list<TLiteralString|TLiteralInt> $key_values
     * @param-out TArray|TKeyedArray $type
     */
    private static function handle_array_access_on_keyed_array(Statements_Analyzer $statements_analyzer, Codebase $codebase, array &$key_values, ?Union $replacement_type, ?Union &$array_access_type, bool $in_assignment, Php_Parser\Node\Expr\Array_Dim_Fetch $stmt, Mutable_Union $offset_type, ?string $extended_var_id, Context $context, T_Keyed_Array &$type, bool $has_mixed, array &$expected_offset_types, bool &$has_valid_offset): void
    {
        $generic_key_type = $type->get_generic_key_type();
        if (!$stmt->dim && $type->fallback_params === null && $type->is_list) {
            $key_values[] = new T_Literal_Int(count($type->properties));
        }
        if ($key_values) {
            $properties = $type->properties;
            foreach ($key_values as $key_value) {
                $string_to_int = Array_Analyzer::get_literal_array_key_int($key_value->value);
                $key_value = $string_to_int === false ? $key_value : new T_Literal_Int($string_to_int);
                if ($type->is_list && (!is_int($key_value->value) || $key_value->value < 0)) {
                    $expected_offset_types[] = $type->get_generic_key_type();
                    $has_valid_offset = false;
                } elseif (isset($properties[$key_value->value]) && !($key_value->value === 0 && Atomic_Type_Comparator::is_legacy_t_list_like($type)) || $replacement_type) {
                    $has_valid_offset = true;
                    if ($replacement_type) {
                        $properties[$key_value->value] = Type::combine_union_types($properties[$key_value->value] ?? null, $replacement_type);
                        if (is_int($key_value->value) && !$stmt->dim && $type->is_list && $type->properties[$key_value->value - 1]->possibly_undefined) {
                            $first = true;
                            for ($x = 0; $x < $key_value->value; $x++) {
                                if (!$properties[$x]->possibly_undefined) {
                                    continue;
                                }
                                $properties[$x] = Type::combine_union_types($properties[$x], $replacement_type);
                                if ($first) {
                                    $first = false;
                                    $properties[$x] = $properties[$x]->set_possibly_undefined(false);
                                }
                            }
                            $properties[$key_value->value] = $properties[$key_value->value]->set_possibly_undefined(true);
                        }
                    }
                    $array_access_type = Type::combine_union_types($array_access_type, $properties[$key_value->value]);
                } elseif ($in_assignment) {
                    $properties[$key_value->value] = new Union([new T_Never()]);
                    $array_access_type = Type::combine_union_types($array_access_type, $properties[$key_value->value]);
                } elseif ($type->fallback_params !== null) {
                    if ($codebase->config->ensure_array_string_offsets_exist) {
                        self::check_literal_string_array_offset($offset_type, $type->get_generic_key_type(), $extended_var_id, $stmt, $context, $statements_analyzer);
                    }
                    if ($codebase->config->ensure_array_int_offsets_exist) {
                        self::check_literal_int_array_offset($offset_type, $type->get_generic_key_type(), $extended_var_id, $stmt, $context, $statements_analyzer);
                    }
                    $properties[$key_value->value] = $type->fallback_params[1];
                    $array_access_type = $type->fallback_params[1];
                } elseif ($has_mixed) {
                    $has_valid_offset = true;
                    $array_access_type = Type::get_mixed();
                } else {
                    $object_like_keys = array_keys($properties);
                    $last_key = array_pop($object_like_keys);
                    $key_string = '';
                    if ($object_like_keys) {
                        $formatted_keys = implode(', ', array_map(
                            /** @param int|string $key */
                            static fn($key): string => is_int($key) ? "{$key}" : '\'' . $key . '\'',
                            $object_like_keys
                        ));
                        $key_string = $formatted_keys . ' or ';
                    }
                    $key_string .= is_int($last_key) ? $last_key : '\'' . $last_key . '\'';
                    $expected_offset_types[] = $key_string;
                    $array_access_type = Type::get_mixed();
                }
            }
            $type = $type->set_properties($properties);
        } else {
            $key_type = $generic_key_type->has_mixed() ? Type::get_array_key() : $generic_key_type;
            $union_comparison_results = new Type_Comparison_Result();
            $is_contained = Union_Type_Comparator::is_contained_by($codebase, $offset_type->freeze(), $key_type, true, $offset_type->ignore_falsable_issues, $union_comparison_results);
            if ($context->inside_isset && !$is_contained) {
                $is_contained = Union_Type_Comparator::is_contained_by($codebase, $key_type, $offset_type->freeze(), true, $offset_type->ignore_falsable_issues);
            }
            if (($is_contained || $union_comparison_results->type_coerced_from_scalar || $union_comparison_results->type_coerced_from_mixed || $in_assignment) && !$union_comparison_results->to_string_cast) {
                if ($replacement_type) {
                    $generic_params = Type::combine_union_types($type->get_generic_value_type(), $replacement_type);
                    $new_key_type = Type::combine_union_types($generic_key_type, $offset_type->is_mixed() ? Type::get_array_key() : $offset_type->freeze());
                    if (!$stmt->dim) {
                        if ($type->is_list) {
                            $type = new T_Keyed_Array($type->properties, null, [$new_key_type, $generic_params], true);
                        } else {
                            $type = new T_Non_Empty_Array([$new_key_type, $generic_params], null, $type->get_min_count() + 1);
                        }
                    } else {
                        $min_count = $type->get_min_count();
                        if ($min_count) {
                            $type = new T_Non_Empty_Array([$new_key_type, $generic_params], null, $min_count);
                        } else {
                            $type = new T_Array([$new_key_type, $generic_params]);
                        }
                    }
                    $array_access_type = Type::combine_union_types($array_access_type, $generic_params);
                } else {
                    $array_access_type = Type::combine_union_types($array_access_type, $type->get_generic_value_type());
                }
                $has_valid_offset = true;
            } else {
                if (!$context->inside_isset || $type->fallback_params === null && !$union_comparison_results->type_coerced) {
                    $expected_offset_types[] = $generic_key_type->get_id();
                }
                $array_access_type = Type::get_mixed();
            }
        }
    }
    public static function validate_array_offset(Statements_Analyzer $statements_analyzer, Expr $stmt, Type\Union|Type\Mutable_Union $array_type, Type\Union|Type\Mutable_Union $offset_type): void
    {
        $literal_offsets = array_keys($offset_type->get_literal_strings());
        if (!$literal_offsets) {
            return;
        }
        foreach ($array_type->get_atomic_types() as $t) {
            if ($t instanceof T_Keyed_Array) {
                return;
            }
            if ($t instanceof T_Array && $t->type_params[0]->all_literals()) {
                return;
            }
        }
        if (Issue_Buffer::accepts(new Literal_Key_Unshaped_Array('Literal offset ' . implode('|', $literal_offsets) . ' was used on unshaped array ' . $array_type, new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues())) {
            // fall through
        }
    }
    private static function handle_array_access_on_named_object(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Array_Dim_Fetch $stmt, T_Named_Object $type, Context $context, bool $in_assignment, ?Php_Parser\Node\Expr $assign_value, ?Union &$array_access_type, bool &$has_array_access): void
    {
        $codebase = $statements_analyzer->get_codebase();
        if (strtolower($type->value) === 'simplexmlelement' || $codebase->class_exists($type->value) && $codebase->class_extends_or_implements($type->value, 'SimpleXMLElement')) {
            $call_array_access_type = new Union([new T_Null(), new T_Named_Object('SimpleXMLElement')]);
        } elseif (strtolower($type->value) === 'domnodelist' && $stmt->dim) {
            $old_data_provider = $statements_analyzer->node_data;
            $statements_analyzer->node_data = clone $statements_analyzer->node_data;
            $fake_method_call = new Virtual_Method_Call($stmt->var, new Virtual_Identifier('item', $stmt->var->get_attributes()), [new Virtual_Arg($stmt->dim)]);
            $suppressed_issues = $statements_analyzer->get_suppressed_issues();
            if (!in_array('PossiblyInvalidMethodCall', $suppressed_issues, true)) {
                $statements_analyzer->add_suppressed_issues(['PossiblyInvalidMethodCall']);
            }
            if (!in_array('MixedMethodCall', $suppressed_issues, true)) {
                $statements_analyzer->add_suppressed_issues(['MixedMethodCall']);
            }
            Method_Call_Analyzer::analyze($statements_analyzer, $fake_method_call, $context);
            if (!in_array('PossiblyInvalidMethodCall', $suppressed_issues, true)) {
                $statements_analyzer->remove_suppressed_issues(['PossiblyInvalidMethodCall']);
            }
            if (!in_array('MixedMethodCall', $suppressed_issues, true)) {
                $statements_analyzer->remove_suppressed_issues(['MixedMethodCall']);
            }
            $call_array_access_type = $statements_analyzer->node_data->get_type($fake_method_call) ?? Type::get_mixed();
            $statements_analyzer->node_data = $old_data_provider;
        } else {
            $suppressed_issues = $statements_analyzer->get_suppressed_issues();
            if (!in_array('PossiblyInvalidMethodCall', $suppressed_issues, true)) {
                $statements_analyzer->add_suppressed_issues(['PossiblyInvalidMethodCall']);
            }
            if (!in_array('MixedMethodCall', $suppressed_issues, true)) {
                $statements_analyzer->add_suppressed_issues(['MixedMethodCall']);
            }
            if ($in_assignment) {
                $old_node_data = $statements_analyzer->node_data;
                $statements_analyzer->node_data = clone $statements_analyzer->node_data;
                $fake_set_method_call = new Virtual_Method_Call($stmt->var, new Virtual_Identifier('offsetSet', $stmt->var->get_attributes()), [new Virtual_Arg($stmt->dim ?? new Virtual_Const_Fetch(new Virtual_Name('null'), $stmt->var->get_attributes())), new Virtual_Arg($assign_value ?? new Virtual_Const_Fetch(new Virtual_Name('null'), $stmt->var->get_attributes()))]);
                Method_Call_Analyzer::analyze($statements_analyzer, $fake_set_method_call, $context);
                $statements_analyzer->node_data = $old_node_data;
            }
            if ($stmt->dim) {
                $old_node_data = $statements_analyzer->node_data;
                $statements_analyzer->node_data = clone $statements_analyzer->node_data;
                $fake_get_method_call = new Virtual_Method_Call($stmt->var, new Virtual_Identifier('offsetGet', $stmt->var->get_attributes()), [new Virtual_Arg($stmt->dim)]);
                Method_Call_Analyzer::analyze($statements_analyzer, $fake_get_method_call, $context);
                $call_array_access_type = $statements_analyzer->node_data->get_type($fake_get_method_call) ?? Type::get_mixed();
                $statements_analyzer->node_data = $old_node_data;
            } else {
                $call_array_access_type = Type::get_void();
            }
            $has_array_access = true;
            if (!in_array('PossiblyInvalidMethodCall', $suppressed_issues, true)) {
                $statements_analyzer->remove_suppressed_issues(['PossiblyInvalidMethodCall']);
            }
            if (!in_array('MixedMethodCall', $suppressed_issues, true)) {
                $statements_analyzer->remove_suppressed_issues(['MixedMethodCall']);
            }
        }
        $array_access_type = Type::combine_union_types($array_access_type, $call_array_access_type);
    }
    /**
     * @param list<string> $expected_offset_types
     */
    private static function handle_array_access_on_string(Statements_Analyzer $statements_analyzer, Codebase $codebase, Php_Parser\Node\Expr\Array_Dim_Fetch $stmt, bool $in_assignment, Context $context, ?Union $replacement_type, T_String $type, Mutable_Union $offset_type, array &$expected_offset_types, ?Union &$array_access_type, bool &$has_valid_offset): void
    {
        if ($in_assignment && $replacement_type) {
            if ($replacement_type->has_mixed()) {
                if (!$context->collect_initializations && !$context->collect_mutations && $statements_analyzer->get_file_path() === $statements_analyzer->get_root_file_path() && (!($parent_source = $statements_analyzer->get_source()) instanceof Function_Like_Analyzer || !$parent_source->get_source() instanceof Trait_Analyzer)) {
                    $codebase->analyzer->increment_mixed_count($statements_analyzer->get_file_path());
                }
                Issue_Buffer::maybe_add(new Mixed_String_Offset_Assignment('Right-hand-side of string offset assignment cannot be mixed', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
            } else if (!$context->collect_initializations && !$context->collect_mutations && $statements_analyzer->get_file_path() === $statements_analyzer->get_root_file_path() && (!($parent_source = $statements_analyzer->get_source()) instanceof Function_Like_Analyzer || !$parent_source->get_source() instanceof Trait_Analyzer)) {
                $codebase->analyzer->increment_non_mixed_count($statements_analyzer->get_file_path());
            }
        }
        if ($type instanceof T_Single_Letter) {
            $valid_offset_type = Type::get_int(false, 0);
        } elseif ($type instanceof T_Literal_String) {
            if ($type->value === '') {
                $valid_offset_type = Type::get_never();
            } elseif (strlen($type->value) < 10) {
                $valid_offsets = [];
                for ($i = -strlen($type->value), $l = strlen($type->value); $i < $l; $i++) {
                    $valid_offsets[] = new T_Literal_Int($i);
                }
                if (!$valid_offsets) {
                    throw new UnexpectedValueException('This is weird');
                }
                $valid_offset_type = new Union($valid_offsets);
            } else {
                $valid_offset_type = Type::get_int();
            }
        } else {
            $valid_offset_type = Type::get_int();
        }
        if (!Union_Type_Comparator::is_contained_by($codebase, $offset_type->freeze(), $valid_offset_type, true)) {
            $expected_offset_types[] = $valid_offset_type->get_id();
            $array_access_type = Type::get_mixed();
        } else {
            $has_valid_offset = true;
            $array_access_type = Type::combine_union_types($array_access_type, Type::get_single_letter());
        }
    }
    /**
     * @param Atomic[] $offset_types
     */
    private static function check_array_offset_type(Mutable_Union $offset_type, array $offset_types, Codebase $codebase): bool
    {
        $has_valid_absolute_offset = false;
        foreach ($offset_types as $atomic_offset_type) {
            if ($atomic_offset_type instanceof T_Class_Constant) {
                $expanded = Type_Expander::expand_atomic($codebase, $atomic_offset_type, $atomic_offset_type->fq_classlike_name, $atomic_offset_type->fq_classlike_name, null, true, true);
                $has_valid_absolute_offset = self::check_array_offset_type($offset_type, $expanded, $codebase);
                if ($has_valid_absolute_offset) {
                    break;
                }
            }
            if ($atomic_offset_type instanceof T_False && $offset_type->ignore_falsable_issues === true) {
                //do nothing
            } elseif ($atomic_offset_type instanceof T_Null && $offset_type->ignore_nullable_issues === true) {
                //do nothing
            } elseif ($atomic_offset_type instanceof T_String || $atomic_offset_type instanceof T_Int || $atomic_offset_type instanceof T_Array_Key || $atomic_offset_type instanceof T_Mixed) {
                $has_valid_absolute_offset = true;
                break;
            } elseif ($atomic_offset_type instanceof T_Template_Param) {
                $has_valid_absolute_offset = self::check_array_offset_type($offset_type, $atomic_offset_type->as->get_atomic_types(), $codebase);
                if ($has_valid_absolute_offset) {
                    break;
                }
            }
        }
        return $has_valid_absolute_offset;
    }
}