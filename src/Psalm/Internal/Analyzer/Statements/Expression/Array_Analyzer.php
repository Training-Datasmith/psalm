<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Codebase\Constant_Type_Resolver;
use Psalm\Internal\Codebase\Taint_Flow_Graph;
use Psalm\Internal\Codebase\Variable_Use_Graph;
use Psalm\Internal\Data_Flow\Data_Flow_Node;
use Psalm\Internal\Data_Flow\Taint_Source;
use Psalm\Internal\Type\Comparator\Union_Type_Comparator;
use Psalm\Internal\Type\Type_Combiner;
use Psalm\Issue\Duplicate_Array_Key;
use Psalm\Issue\Invalid_Array_Offset;
use Psalm\Issue\Invalid_Operand;
use Psalm\Issue\Mixed_Array_Offset;
use Psalm\Issue\ParseError;
use Psalm\Issue_Buffer;
use Psalm\Plugin\Event_Handler\Event\Add_Remove_Taints_Event;
use Psalm\Type;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Array_Key;
use Psalm\Type\Atomic\T_Bool;
use Psalm\Type\Atomic\T_False;
use Psalm\Type\Atomic\T_Float;
use Psalm\Type\Atomic\T_Int;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Atomic\T_Literal_Class_String;
use Psalm\Type\Atomic\T_Literal_Float;
use Psalm\Type\Atomic\T_Literal_Int;
use Psalm\Type\Atomic\T_Mixed;
use Psalm\Type\Atomic\T_Non_Empty_Array;
use Psalm\Type\Atomic\T_Object_With_Properties;
use Psalm\Type\Atomic\T_String;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Atomic\T_True;
use Psalm\Type\Union;
use function array_diff;
use function array_merge;
use function array_values;
use function count;
use function filter_var;
use function in_array;
use function is_int;
use function is_numeric;
use function is_string;
use function trim;
use const FILTER_VALIDATE_INT;
use const PHP_INT_MAX;
/**
 * @internal
 */
final class Array_Analyzer
{
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Array_ $stmt, Context $context): bool
    {
        // if the array is empty, this special type allows us to match any other array type against it
        if (count($stmt->items) === 0) {
            $statements_analyzer->node_data->set_type($stmt, Type::get_empty_array());
            return true;
        }
        $codebase = $statements_analyzer->get_codebase();
        $array_creation_info = new Array_Creation_Info();
        foreach ($stmt->items as $item) {
            if ($item === null) {
                Issue_Buffer::maybe_add(new ParseError('Array element cannot be empty', new Code_Location($statements_analyzer, $stmt)));
                return false;
            }
            self::analyze_array_item($statements_analyzer, $context, $array_creation_info, $item, $codebase);
        }
        if (count($array_creation_info->item_key_atomic_types) !== 0) {
            $item_key_type = Type_Combiner::combine($array_creation_info->item_key_atomic_types, $codebase);
        } else {
            $item_key_type = null;
        }
        if (count($array_creation_info->item_value_atomic_types) !== 0) {
            $item_value_type = Type_Combiner::combine($array_creation_info->item_value_atomic_types, $codebase);
        } else {
            $item_value_type = null;
        }
        // if this array looks like an object-like array, let's return that instead
        if (count($array_creation_info->property_types) !== 0) {
            $atomic_type = new T_Keyed_Array($array_creation_info->property_types, $array_creation_info->class_strings, $array_creation_info->can_create_objectlike ? null : [$item_key_type ?? Type::get_array_key(), $item_value_type ?? Type::get_mixed()], $array_creation_info->all_list);
            $stmt_type = new Union([$atomic_type], ['parent_nodes' => $array_creation_info->parent_taint_nodes]);
            $statements_analyzer->node_data->set_type($stmt, $stmt_type);
            return true;
        }
        if ($item_key_type === null && $item_value_type === null) {
            $statements_analyzer->node_data->set_type($stmt, Type::get_empty_array());
            return true;
        }
        if ($array_creation_info->all_list) {
            if ($array_creation_info->can_be_empty) {
                $array_type = Type::get_list_atomic($item_value_type ?? Type::get_mixed());
            } else {
                $array_type = Type::get_non_empty_list_atomic($item_value_type ?? Type::get_mixed());
            }
            $stmt_type = new Union([$array_type], ['parent_nodes' => $array_creation_info->parent_taint_nodes]);
            $statements_analyzer->node_data->set_type($stmt, $stmt_type);
            return true;
        }
        if ($item_key_type) {
            $bad_types = [];
            $good_types = [];
            foreach ($item_key_type->get_atomic_types() as $atomic_key_type) {
                if ($atomic_key_type instanceof T_Mixed) {
                    Issue_Buffer::maybe_add(new Mixed_Array_Offset('Cannot create mixed offset – expecting array-key', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
                    $bad_types[] = $atomic_key_type;
                    $good_types[] = new T_Array_Key();
                    continue;
                }
                if (!$atomic_key_type instanceof T_String && !$atomic_key_type instanceof T_Int && !$atomic_key_type instanceof T_Array_Key && !$atomic_key_type instanceof T_Template_Param && !($atomic_key_type instanceof T_Object_With_Properties && isset($atomic_key_type->methods['__tostring']))) {
                    Issue_Buffer::maybe_add(new Invalid_Array_Offset('Cannot create offset of type ' . $item_key_type->get_key() . ', expecting array-key', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
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
                $item_key_type = $item_key_type->get_builder()->substitute(Type_Combiner::combine($bad_types, $codebase), Type_Combiner::combine($good_types, $codebase))->freeze();
            }
        }
        $array_args = [$item_key_type && !$item_key_type->has_mixed() ? $item_key_type : Type::get_array_key(), $item_value_type ?? Type::get_mixed()];
        $array_type = $array_creation_info->can_be_empty ? new T_Array($array_args) : new T_Non_Empty_Array($array_args);
        $stmt_type = new Union([$array_type], ['parent_nodes' => $array_creation_info->parent_taint_nodes]);
        $statements_analyzer->node_data->set_type($stmt, $stmt_type);
        return true;
    }
    /**
     * @psalm-assert-if-false !numeric $literal_array_key
     */
    public static function get_literal_array_key_int(string|int $literal_array_key): false|int
    {
        if (is_int($literal_array_key)) {
            return $literal_array_key;
        }
        if (!is_numeric($literal_array_key)) {
            return false;
        }
        // PHP 8 values with whitespace after number are counted as numeric
        // and filter_var treats them as such too
        // ensures that '15 ' will stay '15 '
        if (trim($literal_array_key) !== $literal_array_key) {
            return false;
        }
        // '+5' will pass the filter_var check but won't be changed in keys
        if ($literal_array_key[0] === '+') {
            return false;
        }
        // e.g. 015 is numeric but won't be typecast as it's not a valid int
        return filter_var($literal_array_key, FILTER_VALIDATE_INT);
    }
    private static function analyze_array_item(Statements_Analyzer $statements_analyzer, Context $context, Array_Creation_Info $array_creation_info, Php_Parser\Node\Array_Item $item, Codebase $codebase): void
    {
        if ($item->unpack) {
            if (Expression_Analyzer::analyze($statements_analyzer, $item->value, $context) === false) {
                return;
            }
            $unpacked_array_type = $statements_analyzer->node_data->get_type($item->value);
            if (!$unpacked_array_type) {
                return;
            }
            self::handle_unpacked_array($statements_analyzer, $array_creation_info, $item, $unpacked_array_type, $codebase);
            if (($data_flow_graph = $statements_analyzer->data_flow_graph) && $data_flow_graph instanceof Variable_Use_Graph && $unpacked_array_type->parent_nodes) {
                $var_location = new Code_Location($statements_analyzer->get_source(), $item->value);
                $new_parent_node = Data_Flow_Node::get_for_assignment('array', $var_location);
                $data_flow_graph->add_node($new_parent_node);
                foreach ($unpacked_array_type->parent_nodes as $parent_node) {
                    $data_flow_graph->add_path($parent_node, $new_parent_node, 'arrayvalue-assignment');
                }
                $array_creation_info->parent_taint_nodes += [$new_parent_node->id => $new_parent_node];
            }
            return;
        }
        $item_key_value = null;
        $item_key_type = null;
        $item_is_list_item = false;
        $array_creation_info->can_be_empty = false;
        if ($item->key) {
            $was_inside_general_use = $context->inside_general_use;
            $context->inside_general_use = true;
            if (Expression_Analyzer::analyze($statements_analyzer, $item->key, $context) === false) {
                $context->inside_general_use = $was_inside_general_use;
                return;
            }
            $context->inside_general_use = $was_inside_general_use;
            if ($item_key_type = $statements_analyzer->node_data->get_type($item->key)) {
                $key_type = $item_key_type;
                if ($key_type->is_null()) {
                    $key_type = Type::get_string('');
                }
                if ($item->key instanceof Php_Parser\Node\Scalar\String_ && self::get_literal_array_key_int($item->key->value) !== false) {
                    $key_type = Type::get_int(false, (int) $item->key->value);
                }
                if ($key_type->is_single_string_literal()) {
                    $item_key_literal_type = $key_type->get_single_string_literal();
                    $string_to_int = self::get_literal_array_key_int($item_key_literal_type->value);
                    $item_key_value = $string_to_int === false ? $item_key_literal_type->value : $string_to_int;
                    if (is_string($item_key_value) && $item_key_literal_type instanceof T_Literal_Class_String) {
                        $array_creation_info->class_strings[$item_key_value] = true;
                    }
                } elseif ($key_type->is_single_int_literal()) {
                    $item_key_value = $key_type->get_single_int_literal()->value;
                    if ($item_key_value <= PHP_INT_MAX && $item_key_value > $array_creation_info->int_offset) {
                        if ($item_key_value - 1 === $array_creation_info->int_offset) {
                            $item_is_list_item = true;
                        }
                        $array_creation_info->int_offset = $item_key_value;
                    }
                }
            } else {
                $key_type = Type::get_array_key();
            }
        } else {
            if ($array_creation_info->int_offset === PHP_INT_MAX) {
                Issue_Buffer::maybe_add(new Invalid_Array_Offset('Cannot add an item with an offset beyond PHP_INT_MAX', new Code_Location($statements_analyzer->get_source(), $item)));
                return;
            }
            $item_is_list_item = true;
            $item_key_value = ++$array_creation_info->int_offset;
            $key_atomic_type = new T_Literal_Int($item_key_value);
            $array_creation_info->item_key_atomic_types[] = $key_atomic_type;
            $key_type = new Union([$key_atomic_type]);
        }
        if (Expression_Analyzer::analyze($statements_analyzer, $item->value, $context) === false) {
            return;
        }
        $array_creation_info->all_list = $array_creation_info->all_list && $item_is_list_item;
        if ($item_key_value !== null) {
            if (isset($array_creation_info->array_keys[$item_key_value])) {
                Issue_Buffer::maybe_add(new Duplicate_Array_Key('Key \'' . $item_key_value . '\' already exists on array', new Code_Location($statements_analyzer->get_source(), $item)), $statements_analyzer->get_suppressed_issues());
            }
            $array_creation_info->array_keys[$item_key_value] = true;
        }
        if (($data_flow_graph = $statements_analyzer->data_flow_graph) && ($data_flow_graph instanceof Variable_Use_Graph || !in_array('TaintedInput', $statements_analyzer->get_suppressed_issues()))) {
            if ($item_value_type = $statements_analyzer->node_data->get_type($item->value)) {
                if ($item_value_type->parent_nodes && !($item_value_type->is_single() && $item_value_type->has_literal_value() && $data_flow_graph instanceof Taint_Flow_Graph)) {
                    $var_location = new Code_Location($statements_analyzer->get_source(), $item);
                    $new_parent_node = Data_Flow_Node::get_for_assignment('array' . ($item_key_value !== null ? '[\'' . $item_key_value . '\']' : ''), $var_location);
                    $data_flow_graph->add_node($new_parent_node);
                    $event = new Add_Remove_Taints_Event($item, $context, $statements_analyzer, $codebase);
                    $added_taints = $codebase->config->event_dispatcher->dispatch_add_taints($event);
                    $removed_taints = $codebase->config->event_dispatcher->dispatch_remove_taints($event);
                    $taints = array_diff($added_taints, $removed_taints);
                    if ($taints !== [] && $statements_analyzer->data_flow_graph instanceof Taint_Flow_Graph) {
                        $taint_source = Taint_Source::from_node($new_parent_node);
                        $taint_source->taints = $taints;
                        $statements_analyzer->data_flow_graph->add_source($taint_source);
                    }
                    foreach ($item_value_type->parent_nodes as $parent_node) {
                        $data_flow_graph->add_path($parent_node, $new_parent_node, 'arrayvalue-assignment' . ($item_key_value !== null ? '-\'' . $item_key_value . '\'' : ''), $added_taints, $removed_taints);
                    }
                    $array_creation_info->parent_taint_nodes += [$new_parent_node->id => $new_parent_node];
                }
                if ($item_key_type && $item_key_type->parent_nodes && $item_key_value === null && !($item_key_type->is_single() && $item_key_type->has_literal_value() && $data_flow_graph instanceof Taint_Flow_Graph)) {
                    $var_location = new Code_Location($statements_analyzer->get_source(), $item);
                    $new_parent_node = Data_Flow_Node::get_for_assignment('array', $var_location);
                    $data_flow_graph->add_node($new_parent_node);
                    $event = new Add_Remove_Taints_Event($item, $context, $statements_analyzer, $codebase);
                    $added_taints = $codebase->config->event_dispatcher->dispatch_add_taints($event);
                    $removed_taints = $codebase->config->event_dispatcher->dispatch_remove_taints($event);
                    $taints = array_diff($added_taints, $removed_taints);
                    if ($taints !== [] && $statements_analyzer->data_flow_graph instanceof Taint_Flow_Graph) {
                        $taint_source = Taint_Source::from_node($new_parent_node);
                        $taint_source->taints = $taints;
                        $statements_analyzer->data_flow_graph->add_source($taint_source);
                    }
                    foreach ($item_key_type->parent_nodes as $parent_node) {
                        $data_flow_graph->add_path($parent_node, $new_parent_node, 'arraykey-assignment', $added_taints, $removed_taints);
                    }
                    $array_creation_info->parent_taint_nodes += [$new_parent_node->id => $new_parent_node];
                }
            }
        }
        if ($item->by_ref) {
            $var_id = Expression_Identifier::get_extended_var_id($item->value, $statements_analyzer->get_fqcln(), $statements_analyzer);
            if ($var_id) {
                if (isset($context->vars_in_scope[$var_id])) {
                    $context->remove_descendents($var_id, $context->vars_in_scope[$var_id], null, $statements_analyzer);
                }
                $context->vars_in_scope[$var_id] = Type::get_mixed();
            }
        }
        $config = $codebase->config;
        if ($item_value_type = $statements_analyzer->node_data->get_type($item->value)) {
            if ($item_key_value !== null && count($array_creation_info->property_types) <= $config->max_shaped_array_size) {
                $array_creation_info->property_types[$item_key_value] = $item_value_type;
            } else {
                $array_creation_info->can_create_objectlike = false;
                $array_creation_info->item_key_atomic_types = array_merge($array_creation_info->item_key_atomic_types, array_values($key_type->get_atomic_types()));
                $array_creation_info->item_value_atomic_types = array_merge($array_creation_info->item_value_atomic_types, array_values($item_value_type->get_atomic_types()));
            }
        } else if ($item_key_value !== null && count($array_creation_info->property_types) <= $config->max_shaped_array_size) {
            $array_creation_info->property_types[$item_key_value] = Type::get_mixed();
        } else {
            $array_creation_info->can_create_objectlike = false;
            $array_creation_info->item_key_atomic_types = array_merge($array_creation_info->item_key_atomic_types, array_values($key_type->get_atomic_types()));
            $array_creation_info->item_value_atomic_types[] = new T_Mixed();
        }
    }
    private static function handle_unpacked_array(Statements_Analyzer $statements_analyzer, Array_Creation_Info $array_creation_info, Php_Parser\Node\Array_Item $item, Union $unpacked_array_type, Codebase $codebase): void
    {
        $all_non_empty = true;
        $has_possibly_undefined = false;
        foreach ($unpacked_array_type->get_atomic_types() as $unpacked_atomic_type) {
            if ($unpacked_atomic_type instanceof T_Keyed_Array) {
                foreach ($unpacked_atomic_type->properties as $key => $property_value) {
                    if ($property_value->possibly_undefined) {
                        $has_possibly_undefined = true;
                        continue;
                    }
                    if (is_string($key)) {
                        if ($codebase->analysis_php_version_id <= 80000) {
                            Issue_Buffer::maybe_add(new Duplicate_Array_Key('String keys are not supported in unpacked arrays', new Code_Location($statements_analyzer->get_source(), $item->value)), $statements_analyzer->get_suppressed_issues());
                            continue 2;
                        }
                        $new_offset = $key;
                        $array_creation_info->item_key_atomic_types[] = Type::get_atomic_string_from_literal($new_offset);
                        $array_creation_info->all_list = false;
                    } else {
                        if ($array_creation_info->int_offset === PHP_INT_MAX) {
                            Issue_Buffer::maybe_add(new Invalid_Array_Offset('Cannot add an item with an offset beyond PHP_INT_MAX', new Code_Location($statements_analyzer->get_source(), $item->value)), $statements_analyzer->get_suppressed_issues());
                            continue 2;
                        }
                        $new_offset = ++$array_creation_info->int_offset;
                        $array_creation_info->item_key_atomic_types[] = new T_Literal_Int($new_offset);
                    }
                    $array_creation_info->array_keys[$new_offset] = true;
                    $array_creation_info->property_types[$new_offset] = $property_value;
                }
                if (!$unpacked_atomic_type->is_non_empty()) {
                    $all_non_empty = false;
                }
                if ($has_possibly_undefined) {
                    $unpacked_atomic_type = $unpacked_atomic_type->get_generic_array_type();
                } elseif (!$unpacked_atomic_type->fallback_params) {
                    continue;
                }
            } elseif (!$unpacked_atomic_type instanceof T_Non_Empty_Array) {
                $all_non_empty = false;
            }
            $codebase = $statements_analyzer->get_codebase();
            if (!$unpacked_atomic_type->is_iterable($codebase)) {
                $array_creation_info->can_create_objectlike = false;
                $array_creation_info->item_key_atomic_types[] = new T_Array_Key();
                $array_creation_info->item_value_atomic_types[] = new T_Mixed();
                Issue_Buffer::maybe_add(new Invalid_Operand("Cannot use spread operator on non-iterable type {$unpacked_array_type->get_id()}", new Code_Location($statements_analyzer->get_source(), $item->value)), $statements_analyzer->get_suppressed_issues());
                continue;
            }
            $iterable_type = $unpacked_atomic_type->get_iterable($codebase);
            if ($iterable_type->type_params[0]->is_never()) {
                continue;
            }
            $array_creation_info->can_create_objectlike = false;
            if (!Union_Type_Comparator::is_contained_by($codebase, $iterable_type->type_params[0], Type::get_array_key())) {
                Issue_Buffer::maybe_add(new Invalid_Operand("Cannot use spread operator on iterable with key type " . $iterable_type->type_params[0]->get_id(), new Code_Location($statements_analyzer->get_source(), $item->value)), $statements_analyzer->get_suppressed_issues());
                continue;
            }
            if ($iterable_type->type_params[0]->has_string()) {
                if ($codebase->analysis_php_version_id <= 80000) {
                    Issue_Buffer::maybe_add(new Duplicate_Array_Key('String keys are not supported in unpacked arrays', new Code_Location($statements_analyzer->get_source(), $item->value)), $statements_analyzer->get_suppressed_issues());
                    continue;
                }
                $array_creation_info->all_list = false;
            }
            // Unpacked array might overwrite known properties, so values are merged when the keys intersect.
            foreach ($array_creation_info->property_types as $prop_key_val => $prop_val) {
                $prop_key = new Union([Constant_Type_Resolver::get_literal_type_from_scalar_value($prop_key_val)]);
                // Since $prop_key is a single literal type, the types intersect iff $prop_key is contained by the
                // template type (ie $prop_key cannot overlap with the template type without being contained by it).
                if (Union_Type_Comparator::is_contained_by($codebase, $prop_key, $iterable_type->type_params[0])) {
                    $new_prop_val = Type::combine_union_types($prop_val, $iterable_type->type_params[1]);
                    $array_creation_info->property_types[$prop_key_val] = $new_prop_val;
                }
            }
            $array_creation_info->item_key_atomic_types = array_merge($array_creation_info->item_key_atomic_types, array_values($iterable_type->type_params[0]->get_atomic_types()));
            $array_creation_info->item_value_atomic_types = array_merge($array_creation_info->item_value_atomic_types, array_values($iterable_type->type_params[1]->get_atomic_types()));
        }
        if ($all_non_empty) {
            $array_creation_info->can_be_empty = false;
        }
    }
}