<?php

declare (strict_types=1);
namespace Psalm\Internal\Type\Comparator;

use Psalm\Codebase;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Class_String_Map;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Atomic\T_Literal_Int;
use Psalm\Type\Atomic\T_Literal_String;
use Psalm\Type\Atomic\T_Never;
use Psalm\Type\Atomic\T_Non_Empty_Array;
use Psalm\Type\Union;
/**
 * @internal
 */
final class Array_Type_Comparator
{
    /**
     * @param TArray|TKeyedArray|TClassStringMap $input_type_part
     * @param TArray|TKeyedArray|TClassStringMap $container_type_part
     */
    public static function is_contained_by(Codebase $codebase, Atomic $input_type_part, Atomic $container_type_part, bool $allow_interface_equality, ?Type_Comparison_Result $atomic_comparison_result): bool
    {
        $all_types_contain = true;
        $is_empty_array = $input_type_part->equals(new T_Array([new Union([new T_Never()]), new Union([new T_Never()])]), false);
        if ($is_empty_array && ($container_type_part instanceof T_Array && !$container_type_part instanceof T_Non_Empty_Array || $container_type_part instanceof T_Keyed_Array && !$container_type_part->is_non_empty())) {
            return true;
        }
        if ($container_type_part instanceof T_Keyed_Array && $input_type_part instanceof T_Array) {
            $all_string_int_literals = true;
            $properties = [];
            $value = $input_type_part->type_params[1]->set_possibly_undefined(true);
            foreach ($input_type_part->type_params[0]->get_atomic_types() as $atomic_key_type) {
                if ($atomic_key_type instanceof T_Literal_String || $atomic_key_type instanceof T_Literal_Int) {
                    $properties[$atomic_key_type->value] = $value;
                } else {
                    $all_string_int_literals = false;
                }
            }
            if ($all_string_int_literals && $properties) {
                $input_type_part = new T_Keyed_Array($properties);
                return Keyed_Array_Comparator::is_contained_by($codebase, $input_type_part, $container_type_part, $allow_interface_equality, $atomic_comparison_result);
            }
        }
        if ($container_type_part instanceof T_Keyed_Array && $container_type_part->is_list && ($input_type_part instanceof T_Keyed_Array && !$input_type_part->is_list || $input_type_part instanceof T_Array)) {
            if ($atomic_comparison_result) {
                $atomic_comparison_result->type_coerced = true;
            }
            return false;
        }
        if ($container_type_part instanceof T_Keyed_Array && $container_type_part->is_list && $input_type_part instanceof T_Class_String_Map) {
            return false;
        }
        if ($container_type_part instanceof T_Keyed_Array) {
            $container_type_part = $container_type_part->get_generic_array_type();
        }
        if ($input_type_part instanceof T_Keyed_Array) {
            $input_type_part = $input_type_part->get_generic_array_type();
        }
        if ($input_type_part instanceof T_Class_String_Map) {
            $input_type_part = new T_Array([$input_type_part->get_standin_key_param(), $input_type_part->value_param]);
        }
        if ($container_type_part instanceof T_Class_String_Map) {
            $container_type_part = new T_Array([$container_type_part->get_standin_key_param(), $container_type_part->value_param]);
        }
        foreach ($input_type_part->type_params as $i => $input_param) {
            $container_param = $container_type_part->type_params[$i];
            if ($i === 0 && $input_param->has_mixed() && $container_param->has_string() && $container_param->has_int()) {
                continue;
            }
            if ($input_param->is_never() && $container_type_part instanceof T_Non_Empty_Array) {
                return false;
            }
            $param_comparison_result = new Type_Comparison_Result();
            if (!$input_param->is_never()) {
                if (!Union_Type_Comparator::is_contained_by($codebase, $input_param, $container_param, $input_param->ignore_nullable_issues, $input_param->ignore_falsable_issues, $param_comparison_result, $allow_interface_equality)) {
                    if ($atomic_comparison_result) {
                        $atomic_comparison_result->type_coerced = $param_comparison_result->type_coerced === true && $atomic_comparison_result->type_coerced !== false;
                        $atomic_comparison_result->type_coerced_from_mixed = $param_comparison_result->type_coerced_from_mixed === true && $atomic_comparison_result->type_coerced_from_mixed !== false;
                        $atomic_comparison_result->type_coerced_from_as_mixed = $param_comparison_result->type_coerced_from_as_mixed === true && $atomic_comparison_result->type_coerced_from_as_mixed !== false;
                        $atomic_comparison_result->type_coerced_from_scalar = $param_comparison_result->type_coerced_from_scalar === true && $atomic_comparison_result->type_coerced_from_scalar !== false;
                        $atomic_comparison_result->scalar_type_match_found = $param_comparison_result->scalar_type_match_found === true && $atomic_comparison_result->scalar_type_match_found !== false;
                    }
                    if (!$param_comparison_result->type_coerced_from_as_mixed) {
                        $all_types_contain = false;
                    }
                } else if ($atomic_comparison_result) {
                    $atomic_comparison_result->to_string_cast = $atomic_comparison_result->to_string_cast === true || $param_comparison_result->to_string_cast === true;
                }
            }
        }
        if ($container_type_part instanceof T_Non_Empty_Array && !$input_type_part instanceof T_Non_Empty_Array) {
            if ($all_types_contain && $atomic_comparison_result) {
                $atomic_comparison_result->type_coerced = true;
            }
            return false;
        }
        return $all_types_contain;
    }
}