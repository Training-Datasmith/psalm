<?php

declare (strict_types=1);
namespace Psalm\Internal\Type\Comparator;

use Psalm\Codebase;
use Psalm\Internal\Method_Identifier;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\Scalar;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Callable;
use Psalm\Type\Atomic\T_Callable_Interface;
use Psalm\Type\Atomic\T_Callable_Keyed_Array;
use Psalm\Type\Atomic\T_Callable_Object;
use Psalm\Type\Atomic\T_Callable_String;
use Psalm\Type\Atomic\T_Class_String_Map;
use Psalm\Type\Atomic\T_Closure;
use Psalm\Type\Atomic\T_Conditional;
use Psalm\Type\Atomic\T_Empty_Mixed;
use Psalm\Type\Atomic\T_Enum_Case;
use Psalm\Type\Atomic\T_Generic_Object;
use Psalm\Type\Atomic\T_Iterable;
use Psalm\Type\Atomic\T_Key_Of;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Atomic\T_Literal_String;
use Psalm\Type\Atomic\T_Mixed;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Never;
use Psalm\Type\Atomic\T_Non_Empty_Array;
use Psalm\Type\Atomic\T_Non_Empty_Mixed;
use Psalm\Type\Atomic\T_Null;
use Psalm\Type\Atomic\T_Object;
use Psalm\Type\Atomic\T_Object_With_Properties;
use Psalm\Type\Atomic\T_Scalar;
use Psalm\Type\Atomic\T_String;
use Psalm\Type\Atomic\T_Template_Key_Of;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Atomic\T_Template_Value_Of;
use Psalm\Type\Atomic\T_Value_Of;
use Psalm\Type\Union;
use function array_merge;
use function array_values;
use function assert;
use function count;
use function strtolower;
/**
 * @internal
 */
final class Atomic_Type_Comparator
{
    /**
     * Does the input param atomic type match the given param atomic type
     */
    public static function is_contained_by(Codebase $codebase, Atomic $input_type_part, Atomic $container_type_part, bool $allow_interface_equality = false, bool $allow_float_int_equality = true, ?Type_Comparison_Result $atomic_comparison_result = null): bool
    {
        if (($container_type_part instanceof T_Template_Param || $container_type_part instanceof T_Named_Object && $container_type_part->extra_types) && ($input_type_part instanceof T_Template_Param || $input_type_part instanceof T_Named_Object && $input_type_part->extra_types)) {
            return Object_Comparator::is_shallowly_contained_by($codebase, $input_type_part, $container_type_part, $allow_interface_equality, $atomic_comparison_result);
        }
        if ($input_type_part instanceof T_Value_Of) {
            if ($container_type_part instanceof T_Value_Of) {
                return Union_Type_Comparator::is_contained_by($codebase, $input_type_part->type, $container_type_part->type, false, false, null, false, false);
            }
            if ($container_type_part instanceof Scalar) {
                return Union_Type_Comparator::is_contained_by($codebase, T_Value_Of::get_value_type($input_type_part->type, $codebase) ?? $input_type_part->type, new Union([$container_type_part]), false, false, null, false, false);
            }
        }
        if ($container_type_part instanceof T_Mixed || $container_type_part instanceof T_Template_Param && $container_type_part->as->is_mixed() && !$container_type_part->extra_types && $input_type_part instanceof T_Mixed) {
            if ($input_type_part::class === T_Mixed::class && ($container_type_part::class === T_Empty_Mixed::class || $container_type_part::class === T_Non_Empty_Mixed::class)) {
                if ($atomic_comparison_result) {
                    $atomic_comparison_result->type_coerced = true;
                    $atomic_comparison_result->type_coerced_from_mixed = true;
                }
                return false;
            }
            return true;
        }
        if ($input_type_part instanceof T_Never) {
            return true;
        }
        if ($input_type_part instanceof T_Mixed || $input_type_part instanceof T_Template_Param && $input_type_part->as->is_mixed() && !$input_type_part->extra_types) {
            if ($atomic_comparison_result) {
                $atomic_comparison_result->type_coerced = true;
                $atomic_comparison_result->type_coerced_from_mixed = true;
            }
            return false;
        }
        if ($input_type_part instanceof T_Null) {
            if ($container_type_part instanceof T_Null) {
                return true;
            }
            if ($container_type_part instanceof T_Template_Param && ($container_type_part->as->is_nullable() || $container_type_part->as->is_mixed())) {
                return true;
            }
            return false;
        }
        if ($container_type_part instanceof T_Null) {
            return false;
        }
        if ($input_type_part instanceof Scalar && $container_type_part instanceof Scalar) {
            return Scalar_Type_Comparator::is_contained_by($codebase, $input_type_part, $container_type_part, $allow_interface_equality, $allow_float_int_equality, $atomic_comparison_result);
        }
        if ($input_type_part instanceof T_Callable_Keyed_Array && $container_type_part instanceof T_Array) {
            return Array_Type_Comparator::is_contained_by($codebase, $input_type_part, $container_type_part, $allow_interface_equality, $atomic_comparison_result);
        }
        if ($container_type_part instanceof T_Callable && $input_type_part instanceof T_Callable_Interface || $container_type_part instanceof T_Closure && $input_type_part instanceof T_Closure) {
            return Callable_Type_Comparator::is_contained_by($codebase, $input_type_part, $container_type_part, $atomic_comparison_result);
        }
        if ($container_type_part instanceof T_Closure) {
            if ($input_type_part instanceof T_Callable) {
                if (Callable_Type_Comparator::is_contained_by($codebase, $input_type_part, $container_type_part, $atomic_comparison_result) === false) {
                    return false;
                }
                if ($atomic_comparison_result) {
                    $atomic_comparison_result->type_coerced = true;
                }
            }
            return false;
        }
        if ($container_type_part instanceof T_Callable && $input_type_part instanceof T_Closure) {
            return Callable_Type_Comparator::is_contained_by($codebase, $input_type_part, $container_type_part, $atomic_comparison_result);
        }
        if ($input_type_part instanceof T_Named_Object && $input_type_part->value === 'Closure' && $container_type_part instanceof T_Callable) {
            return true;
        }
        if ($input_type_part instanceof T_Object && $container_type_part instanceof T_Callable) {
            return true;
        }
        if ($input_type_part instanceof T_Callable_Object && $container_type_part instanceof T_Object) {
            return true;
        }
        if ($container_type_part instanceof T_Object_With_Properties && $container_type_part->is_stringable_object_only) {
            if ($input_type_part instanceof T_Object_With_Properties && $input_type_part->is_stringable_object_only || $input_type_part instanceof T_Named_Object && $codebase->method_exists(new Method_Identifier($input_type_part->value, '__tostring'))) {
                return true;
            }
            return false;
        }
        if ($container_type_part instanceof T_Named_Object && $container_type_part->value === 'Stringable' && $codebase->analysis_php_version_id >= 80000 && $input_type_part instanceof T_Object_With_Properties && $input_type_part->is_stringable_object_only) {
            return true;
        }
        if ($container_type_part instanceof T_Keyed_Array && $input_type_part instanceof T_Keyed_Array || $container_type_part instanceof T_Object_With_Properties && $input_type_part instanceof T_Object_With_Properties) {
            return Keyed_Array_Comparator::is_contained_by($codebase, $input_type_part, $container_type_part, $allow_interface_equality, $atomic_comparison_result);
        }
        if ($container_type_part instanceof T_Object_With_Properties && $input_type_part instanceof T_Object && !$input_type_part instanceof T_Object_With_Properties && !$input_type_part instanceof T_Callable_Object) {
            if ($atomic_comparison_result) {
                $atomic_comparison_result->type_coerced = true;
            }
            return false;
        }
        if (($input_type_part instanceof T_Array || $input_type_part instanceof T_Keyed_Array || $input_type_part instanceof T_Class_String_Map) && ($container_type_part instanceof T_Array || $container_type_part instanceof T_Keyed_Array || $container_type_part instanceof T_Class_String_Map)) {
            return Array_Type_Comparator::is_contained_by($codebase, $input_type_part, $container_type_part, $allow_interface_equality, $atomic_comparison_result);
        }
        if ($container_type_part::class === T_Named_Object::class && $input_type_part instanceof T_Enum_Case && $input_type_part->value === $container_type_part->value) {
            return true;
        }
        if ($input_type_part::class === T_Named_Object::class && $container_type_part instanceof T_Enum_Case && $input_type_part->value === $container_type_part->value) {
            if ($atomic_comparison_result) {
                $atomic_comparison_result->type_coerced = true;
            }
            return true;
        }
        if ($container_type_part instanceof T_Enum_Case && $input_type_part instanceof T_Enum_Case) {
            return $container_type_part->value === $input_type_part->value && $container_type_part->case_name === $input_type_part->case_name;
        }
        if (($input_type_part instanceof T_Named_Object || $input_type_part instanceof T_Template_Param && $input_type_part->as->has_object_type() || $input_type_part instanceof T_Iterable) && ($container_type_part instanceof T_Named_Object || $container_type_part instanceof T_Template_Param && $container_type_part->is_object_type() || $container_type_part instanceof T_Iterable) && Object_Comparator::is_shallowly_contained_by($codebase, $input_type_part, $container_type_part, $allow_interface_equality, $atomic_comparison_result)) {
            if ($container_type_part instanceof T_Generic_Object || $container_type_part instanceof T_Iterable) {
                return Generic_Type_Comparator::is_contained_by($codebase, $input_type_part, $container_type_part, $allow_interface_equality, $atomic_comparison_result);
            }
            if ($container_type_part instanceof T_Named_Object && $input_type_part instanceof T_Named_Object && $container_type_part->is_static && !$input_type_part->is_static) {
                if ($atomic_comparison_result) {
                    $atomic_comparison_result->type_coerced = true;
                }
                return false;
            }
            if ($atomic_comparison_result) {
                $atomic_comparison_result->to_string_cast = false;
            }
            return true;
        }
        if ($input_type_part::class === T_Object::class && $container_type_part::class === T_Object::class) {
            return true;
        }
        if ($container_type_part instanceof T_Template_Key_Of) {
            if (!$input_type_part instanceof T_Template_Key_Of) {
                return false;
            }
            return Union_Type_Comparator::is_contained_by($codebase, $input_type_part->as, $container_type_part->as);
        }
        if ($input_type_part instanceof T_Template_Key_Of) {
            $array_key_type = T_Key_Of::get_array_key_type($input_type_part->as);
            if ($array_key_type === null) {
                return false;
            }
            foreach ($array_key_type->get_atomic_types() as $array_key_atomic) {
                if (!self::is_contained_by($codebase, $array_key_atomic, $container_type_part, $allow_interface_equality, $allow_float_int_equality, $atomic_comparison_result)) {
                    return false;
                }
            }
            return true;
        }
        if ($container_type_part instanceof T_Template_Value_Of) {
            if (!$input_type_part instanceof T_Template_Value_Of) {
                return false;
            }
            return Union_Type_Comparator::is_contained_by($codebase, $input_type_part->as, $container_type_part->as);
        }
        if ($input_type_part instanceof T_Template_Value_Of) {
            $array_value_type = T_Value_Of::get_value_type($input_type_part->as, $codebase);
            if ($array_value_type === null) {
                return false;
            }
            foreach ($array_value_type->get_atomic_types() as $array_value_atomic) {
                if (!self::is_contained_by($codebase, $array_value_atomic, $container_type_part, $allow_interface_equality, $allow_float_int_equality, $atomic_comparison_result)) {
                    return false;
                }
            }
            return true;
        }
        if ($container_type_part instanceof T_Template_Param && $input_type_part instanceof T_Template_Param) {
            return Union_Type_Comparator::is_contained_by($codebase, $input_type_part->as, $container_type_part->as, false, false, $atomic_comparison_result, $allow_interface_equality);
        }
        if ($container_type_part instanceof T_Template_Param) {
            foreach ($container_type_part->as->get_atomic_types() as $container_as_type_part) {
                if (self::is_contained_by($codebase, $input_type_part, $container_as_type_part, $allow_interface_equality, $allow_float_int_equality, $atomic_comparison_result)) {
                    if ($allow_interface_equality) {
                        return true;
                    }
                }
            }
            return false;
        }
        if ($container_type_part instanceof T_Conditional) {
            $atomic_types = array_merge(array_values($container_type_part->if_type->get_atomic_types()), array_values($container_type_part->else_type->get_atomic_types()));
            foreach ($atomic_types as $container_as_type_part) {
                if (self::is_contained_by($codebase, $input_type_part, $container_as_type_part, $allow_interface_equality, $allow_float_int_equality, $atomic_comparison_result)) {
                    return true;
                }
            }
            return false;
        }
        if ($input_type_part instanceof T_Template_Param) {
            foreach ($input_type_part->extra_types as $extra_type) {
                if (self::is_contained_by($codebase, $extra_type, $container_type_part, $allow_interface_equality, $allow_float_int_equality, $atomic_comparison_result)) {
                    return true;
                }
            }
            foreach ($input_type_part->as->get_atomic_types() as $input_as_type_part) {
                if (self::is_contained_by($codebase, $input_as_type_part, $container_type_part, $allow_interface_equality, $allow_float_int_equality, $atomic_comparison_result)) {
                    return true;
                }
            }
            return false;
        }
        if ($input_type_part instanceof T_Conditional) {
            $input_atomic_types = array_merge(array_values($input_type_part->if_type->get_atomic_types()), array_values($input_type_part->else_type->get_atomic_types()));
            foreach ($input_atomic_types as $input_as_type_part) {
                if (self::is_contained_by($codebase, $input_as_type_part, $container_type_part, $allow_interface_equality, $allow_float_int_equality, $atomic_comparison_result)) {
                    return true;
                }
            }
            return false;
        }
        if ($input_type_part instanceof T_Named_Object && $input_type_part->value === 'static' && $container_type_part instanceof T_Named_Object && strtolower($container_type_part->value) === 'self') {
            return true;
        }
        if ($container_type_part instanceof T_Iterable) {
            if ($input_type_part instanceof T_Array || $input_type_part instanceof T_Keyed_Array) {
                if ($input_type_part instanceof T_Keyed_Array) {
                    $input_type_part = $input_type_part->get_generic_array_type();
                }
                $all_types_contain = true;
                foreach ($input_type_part->type_params as $i => $input_param) {
                    $container_param_offset = $i - (2 - count($container_type_part->type_params));
                    $container_param = $container_type_part->type_params[$container_param_offset];
                    if ($i === 0 && $input_param->has_mixed() && $container_param->has_string() && $container_param->has_int()) {
                        continue;
                    }
                    $array_comparison_result = new Type_Comparison_Result();
                    if (!$input_param->is_never()) {
                        if (!Union_Type_Comparator::is_contained_by($codebase, $input_param, $container_param, $input_param->ignore_nullable_issues, $input_param->ignore_falsable_issues, $array_comparison_result, $allow_interface_equality) && !$array_comparison_result->type_coerced_from_scalar) {
                            if ($atomic_comparison_result && $array_comparison_result->type_coerced_from_mixed) {
                                $atomic_comparison_result->type_coerced_from_mixed = true;
                            }
                            $all_types_contain = false;
                        } else if ($atomic_comparison_result) {
                            $atomic_comparison_result->to_string_cast = $atomic_comparison_result->to_string_cast === true || $array_comparison_result->to_string_cast === true;
                        }
                    }
                }
                return $all_types_contain;
            }
            if ($input_type_part->has_traversable_interface($codebase)) {
                return true;
            }
        }
        if ($container_type_part instanceof T_String || $container_type_part instanceof T_Scalar) {
            if ($input_type_part instanceof T_Named_Object) {
                // check whether the object has a __toString method
                if ($codebase->class_or_interface_exists($input_type_part->value)) {
                    if ($codebase->analysis_php_version_id >= 80000 && ($input_type_part->value === 'Stringable' || $codebase->classlikes->class_exists($input_type_part->value) && $codebase->classlikes->class_implements($input_type_part->value, 'Stringable') || $codebase->classlikes->interface_extends($input_type_part->value, 'Stringable'))) {
                        if ($atomic_comparison_result) {
                            $atomic_comparison_result->to_string_cast = true;
                        }
                        return true;
                    }
                    if ($codebase->methods->method_exists(new Method_Identifier($input_type_part->value, '__tostring'))) {
                        if ($atomic_comparison_result) {
                            $atomic_comparison_result->to_string_cast = true;
                        }
                        return true;
                    }
                }
                // PHP 5.6 doesn't support this natively, so this introduces a bug *just* when checking PHP 5.6 code
                if ($input_type_part->value === 'ReflectionType') {
                    if ($atomic_comparison_result) {
                        $atomic_comparison_result->to_string_cast = true;
                    }
                    return true;
                }
            } elseif ($input_type_part instanceof T_Object_With_Properties && isset($input_type_part->methods['__tostring'])) {
                if ($atomic_comparison_result) {
                    $atomic_comparison_result->to_string_cast = true;
                }
                return true;
            }
        }
        if ($container_type_part instanceof T_Callable && ($input_type_part instanceof T_Literal_String || $input_type_part instanceof T_Callable_String || $input_type_part instanceof T_Array || $input_type_part instanceof T_Keyed_Array || $input_type_part instanceof T_Named_Object && $codebase->class_or_interface_exists($input_type_part->value) && $codebase->method_exists($input_type_part->value . '::__invoke'))) {
            return Callable_Type_Comparator::is_not_explicitly_callable_type_callable($codebase, $input_type_part, $container_type_part, $atomic_comparison_result);
        }
        if ($container_type_part instanceof T_Object && $input_type_part instanceof T_Named_Object) {
            if ($container_type_part instanceof T_Object_With_Properties && $input_type_part->value !== 'stdClass') {
                return Keyed_Array_Comparator::is_contained_by_object_with_properties($codebase, $input_type_part, $container_type_part, $allow_interface_equality, $atomic_comparison_result);
            }
            return true;
        }
        if ($container_type_part instanceof T_Named_Object && $input_type_part instanceof T_Named_Object && $container_type_part->is_static && !$input_type_part->is_static) {
            if ($atomic_comparison_result) {
                $atomic_comparison_result->type_coerced = true;
            }
            return false;
        }
        if ($input_type_part instanceof T_Object && $container_type_part instanceof T_Named_Object) {
            if ($atomic_comparison_result) {
                $atomic_comparison_result->type_coerced = true;
            }
            return false;
        }
        if ($container_type_part instanceof T_Named_Object && $input_type_part instanceof T_Named_Object && $codebase->class_or_interface_or_enum_exists($input_type_part->value) && ($codebase->class_exists($container_type_part->value) && $codebase->class_extends_or_implements($container_type_part->value, $input_type_part->value) || $codebase->interface_exists($container_type_part->value) && $codebase->interface_extends($container_type_part->value, $input_type_part->value))) {
            if ($atomic_comparison_result) {
                $atomic_comparison_result->type_coerced = true;
            }
            return false;
        }
        return $input_type_part->get_key() === $container_type_part->get_key();
    }
    /**
     * @psalm-assert-if-true TKeyedArray $array
     */
    public static function is_legacy_t_list_like(Atomic $array): bool
    {
        return $array instanceof T_Keyed_Array && $array->is_list && $array->fallback_params && count($array->properties) === 1 && $array->properties[0]->possibly_undefined && $array->properties[0]->equals($array->fallback_params[1], true, true, false);
    }
    /**
     * @psalm-assert-if-true TKeyedArray $array
     */
    public static function is_legacy_t_non_empty_list_like(Atomic $array): bool
    {
        return $array instanceof T_Keyed_Array && $array->is_list && $array->fallback_params && count($array->properties) === 1 && !$array->properties[0]->possibly_undefined && $array->properties[0]->equals($array->fallback_params[1]);
    }
    /**
     * Does the input param atomic type match the given param atomic type
     */
    public static function can_be_identical(Codebase $codebase, Atomic $type1_part, Atomic $type2_part, bool $allow_interface_equality = true): bool
    {
        if (self::is_legacy_t_list_like($type1_part) && self::is_legacy_t_non_empty_list_like($type2_part) || self::is_legacy_t_list_like($type2_part) && self::is_legacy_t_non_empty_list_like($type1_part)) {
            assert($type1_part->fallback_params !== null);
            assert($type2_part->fallback_params !== null);
            return Union_Type_Comparator::can_expression_types_be_identical($codebase, $type1_part->fallback_params[1], $type2_part->fallback_params[1]);
        }
        if ($type1_part::class === T_Array::class && $type2_part instanceof T_Non_Empty_Array || $type2_part::class === T_Array::class && $type1_part instanceof T_Non_Empty_Array) {
            return Union_Type_Comparator::can_expression_types_be_identical($codebase, $type1_part->type_params[0], $type2_part->type_params[0]) && Union_Type_Comparator::can_expression_types_be_identical($codebase, $type1_part->type_params[1], $type2_part->type_params[1]);
        }
        $first_comparison_result = new Type_Comparison_Result();
        $second_comparison_result = new Type_Comparison_Result();
        return self::is_contained_by($codebase, $type1_part, $type2_part, $allow_interface_equality, false, $first_comparison_result) && !$first_comparison_result->to_string_cast || self::is_contained_by($codebase, $type2_part, $type1_part, $allow_interface_equality, false, $second_comparison_result) && !$second_comparison_result->to_string_cast || $first_comparison_result->type_coerced && $second_comparison_result->type_coerced;
    }
}