<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider\Return_Type_Provider;

use Override;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Type\Type_Combiner;
use Psalm\Plugin\Event_Handler\Event\Function_Return_Type_Provider_Event;
use Psalm\Plugin\Event_Handler\Function_Return_Type_Provider_Interface;
use Psalm\Type;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_False;
use Psalm\Type\Atomic\T_Int;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Atomic\T_Mixed;
use Psalm\Type\Atomic\T_Non_Empty_Array;
use Psalm\Type\Atomic\T_Null;
use Psalm\Type\Union;
use function array_merge;
use function array_values;
use function count;
use function is_string;
use function max;
/**
 * @internal
 */
final class Array_Merge_Return_Type_Provider implements Function_Return_Type_Provider_Interface
{
    /**
     * @return array<lowercase-string>
     */
    #[Override]
    public static function get_function_ids(): array
    {
        return ['array_merge', 'array_replace'];
    }
    #[Override]
    public static function get_function_return_type(Function_Return_Type_Provider_Event $event): Union
    {
        $statements_source = $event->get_statements_source();
        $call_args = $event->get_call_args();
        if (!$statements_source instanceof Statements_Analyzer || !$call_args) {
            return Type::get_mixed();
        }
        $is_replace = $event->get_function_id() === 'array_replace';
        $inner_value_types = [];
        $inner_key_types = [];
        $codebase = $statements_source->get_codebase();
        $generic_properties = [];
        $class_strings = [];
        $all_keyed_arrays = true;
        $all_int_offsets = true;
        $all_nonempty_lists = true;
        $any_nonempty = false;
        $all_empty = true;
        $max_keyed_array_size = 0;
        foreach ($call_args as $call_arg) {
            if (!$call_arg_type = $statements_source->node_data->get_type($call_arg->value)) {
                return Type::get_array();
            }
            foreach ($call_arg_type->get_atomic_types() as $type_part) {
                $unpacking_indefinite_number_of_args = false;
                $unpacking_possibly_empty = false;
                if ($call_arg->unpack) {
                    if ($type_part instanceof T_Keyed_Array) {
                        if (!$type_part->fallback_params && $type_part->get_min_count() === $type_part->get_max_count()) {
                            $unpacked_type_parts = [];
                            foreach ($type_part->properties as $t) {
                                $unpacked_type_parts = array_merge($unpacked_type_parts, $t->get_atomic_types());
                            }
                        } else {
                            $unpacked_type_parts = $type_part->get_generic_value_type()->get_atomic_types();
                            $unpacking_indefinite_number_of_args = true;
                        }
                        $unpacking_possibly_empty = !$type_part->is_non_empty();
                    } elseif ($type_part instanceof T_Array) {
                        $unpacked_type_parts = $type_part->type_params[1];
                        $unpacking_indefinite_number_of_args = true;
                        $unpacking_possibly_empty = !$type_part instanceof T_Non_Empty_Array;
                        $unpacked_type_parts = $unpacked_type_parts->get_atomic_types();
                    } else {
                        return Type::get_array();
                    }
                } else {
                    $unpacked_type_parts = [$type_part];
                }
                foreach ($unpacked_type_parts as $unpacked_type_part) {
                    if ($unpacked_type_part instanceof T_False && $call_arg_type->ignore_falsable_issues) {
                        continue;
                    }
                    if ($unpacked_type_part instanceof T_Null && $call_arg_type->ignore_nullable_issues) {
                        continue;
                    }
                    if ($unpacked_type_part instanceof T_Keyed_Array) {
                        $all_empty = false;
                        $max_keyed_array_size = max($max_keyed_array_size, count($unpacked_type_part->properties));
                        $added_inner_values = false;
                        foreach ($unpacked_type_part->properties as $key => $type) {
                            if (!$type->possibly_undefined && !$unpacking_possibly_empty) {
                                $any_nonempty = true;
                            }
                            if (is_string($key)) {
                                $all_int_offsets = false;
                            } elseif (!$is_replace) {
                                if ($unpacking_indefinite_number_of_args || $type->possibly_undefined) {
                                    $added_inner_values = true;
                                    $inner_value_types = array_merge($inner_value_types, array_values($type->get_atomic_types()));
                                } else {
                                    $generic_properties[] = $type;
                                }
                                continue;
                            }
                            if (isset($unpacked_type_part->class_strings[$key])) {
                                $class_strings[$key] = true;
                            }
                            if (!isset($generic_properties[$key]) || !$type->possibly_undefined && !$unpacking_possibly_empty) {
                                if ($unpacking_possibly_empty) {
                                    $type = $type->set_possibly_undefined(true);
                                }
                                $generic_properties[$key] = $type;
                            } else {
                                $was_possibly_undefined = $generic_properties[$key]->possibly_undefined || $unpacking_possibly_empty;
                                $generic_properties[$key] = Type::combine_union_types($generic_properties[$key], $type, $codebase, false, true, 500, $was_possibly_undefined);
                            }
                        }
                        if (!$unpacked_type_part->is_list) {
                            $all_nonempty_lists = false;
                        }
                        if ($added_inner_values) {
                            $all_keyed_arrays = false;
                            $inner_key_types[] = new T_Int();
                        }
                        if ($unpacked_type_part->fallback_params !== null) {
                            $all_keyed_arrays = false;
                            $inner_value_types = array_merge($inner_value_types, array_values($unpacked_type_part->fallback_params[1]->get_atomic_types()));
                            $inner_key_types = array_merge($inner_key_types, array_values($unpacked_type_part->fallback_params[0]->get_atomic_types()));
                        }
                        continue;
                    }
                    if ($unpacked_type_part instanceof T_Mixed && $unpacked_type_part->from_loop_isset) {
                        $unpacked_type_part = new T_Array([Type::get_array_key(), Type::get_mixed(true)]);
                    }
                    if ($unpacked_type_part instanceof T_Array) {
                        if ($unpacked_type_part->is_empty_array()) {
                            continue;
                        }
                        foreach ($generic_properties as $key => $keyed_type) {
                            $generic_properties[$key] = Type::combine_union_types($keyed_type, $unpacked_type_part->type_params[1], $codebase);
                        }
                        $all_keyed_arrays = false;
                        $all_nonempty_lists = false;
                        if (!$unpacked_type_part->type_params[0]->is_int()) {
                            $all_int_offsets = false;
                        }
                        if ($unpacked_type_part instanceof T_Non_Empty_Array && !$unpacking_possibly_empty) {
                            $any_nonempty = true;
                        }
                    } else {
                        return Type::get_array();
                    }
                    $all_empty = false;
                    $inner_key_types = array_merge($inner_key_types, array_values($unpacked_type_part->type_params[0]->get_atomic_types()));
                    $inner_value_types = array_merge($inner_value_types, array_values($unpacked_type_part->type_params[1]->get_atomic_types()));
                }
            }
        }
        $inner_key_type = null;
        $inner_value_type = null;
        if ($inner_key_types) {
            $inner_key_type = Type_Combiner::combine($inner_key_types, $codebase, true);
        }
        if ($inner_value_types) {
            $inner_value_type = Type_Combiner::combine($inner_value_types, $codebase, true);
        }
        $generic_property_count = count($generic_properties);
        if ($generic_properties && $generic_property_count < 64 && ($generic_property_count < $max_keyed_array_size * 2 || $generic_property_count < 16)) {
            $objectlike = new T_Keyed_Array($generic_properties, $class_strings ?: null, $all_keyed_arrays || $inner_key_type === null || $inner_value_type === null ? null : [$inner_key_type, $inner_value_type], $all_nonempty_lists || $all_int_offsets);
            return new Union([$objectlike]);
        }
        if ($all_empty) {
            return Type::get_empty_array();
        }
        if ($inner_value_type) {
            if ($all_int_offsets) {
                if ($any_nonempty) {
                    return Type::get_non_empty_list($inner_value_type);
                }
                return Type::get_list($inner_value_type);
            }
            $inner_key_type ??= Type::get_array_key();
            if ($any_nonempty) {
                return new Union([new T_Non_Empty_Array([$inner_key_type, $inner_value_type])]);
            }
            return new Union([new T_Array([$inner_key_type, $inner_value_type])]);
        }
        return Type::get_array();
    }
}