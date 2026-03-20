<?php

declare (strict_types=1);
namespace Psalm\Internal\Type\Comparator;

use Psalm\Codebase;
use Psalm\Internal\Analyzer\Class_Like_Analyzer;
use Psalm\Type\Atomic\Scalar;
use Psalm\Type\Atomic\T_Array_Key;
use Psalm\Type\Atomic\T_Bool;
use Psalm\Type\Atomic\T_Callable_String;
use Psalm\Type\Atomic\T_Class_String;
use Psalm\Type\Atomic\T_Dependent_Get_Class;
use Psalm\Type\Atomic\T_Dependent_Get_Debug_Type;
use Psalm\Type\Atomic\T_Dependent_Get_Type;
use Psalm\Type\Atomic\T_False;
use Psalm\Type\Atomic\T_Float;
use Psalm\Type\Atomic\T_Int;
use Psalm\Type\Atomic\T_Int_Range;
use Psalm\Type\Atomic\T_Literal_Class_String;
use Psalm\Type\Atomic\T_Literal_Float;
use Psalm\Type\Atomic\T_Literal_Int;
use Psalm\Type\Atomic\T_Literal_String;
use Psalm\Type\Atomic\T_Lowercase_String;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Non_Empty_Lowercase_String;
use Psalm\Type\Atomic\T_Non_Empty_Nonspecific_Literal_String;
use Psalm\Type\Atomic\T_Non_Empty_String;
use Psalm\Type\Atomic\T_Non_Falsy_String;
use Psalm\Type\Atomic\T_Nonspecific_Literal_Int;
use Psalm\Type\Atomic\T_Nonspecific_Literal_String;
use Psalm\Type\Atomic\T_Numeric;
use Psalm\Type\Atomic\T_Numeric_String;
use Psalm\Type\Atomic\T_Scalar;
use Psalm\Type\Atomic\T_Single_Letter;
use Psalm\Type\Atomic\T_String;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Atomic\T_Template_Param_Class;
use Psalm\Type\Atomic\T_Trait_String;
use Psalm\Type\Atomic\T_True;
use function is_numeric;
use function strtolower;
/**
 * @internal
 */
final class Scalar_Type_Comparator
{
    public static function is_contained_by(Codebase $codebase, Scalar $input_type_part, Scalar $container_type_part, bool $allow_interface_equality = false, bool $allow_float_int_equality = true, ?Type_Comparison_Result $atomic_comparison_result = null): bool
    {
        if ($container_type_part::class === T_String::class && $input_type_part instanceof T_String) {
            return true;
        }
        if ($container_type_part::class === T_Int::class && $input_type_part instanceof T_Int) {
            return true;
        }
        if ($container_type_part::class === T_Float::class && $input_type_part instanceof T_Float) {
            return true;
        }
        if (($container_type_part instanceof T_Non_Empty_String || $container_type_part instanceof T_Non_Empty_Nonspecific_Literal_String) && ($input_type_part::class === T_String::class || $input_type_part::class === T_Nonspecific_Literal_String::class || $input_type_part::class === T_Lowercase_String::class)) {
            if ($atomic_comparison_result) {
                $atomic_comparison_result->type_coerced = true;
            }
            return false;
        }
        if ($container_type_part instanceof T_Nonspecific_Literal_String && ($input_type_part instanceof T_Literal_String || $input_type_part instanceof T_Nonspecific_Literal_String)) {
            if ($container_type_part instanceof T_Non_Empty_Nonspecific_Literal_String) {
                return $input_type_part instanceof T_Literal_String && $input_type_part->value !== '' || $input_type_part instanceof T_Non_Empty_Nonspecific_Literal_String;
            }
            return true;
        }
        if ($container_type_part instanceof T_Nonspecific_Literal_String) {
            if ($input_type_part instanceof T_String) {
                if ($atomic_comparison_result) {
                    $atomic_comparison_result->type_coerced = true;
                }
            }
            return false;
        }
        if ($container_type_part instanceof T_Nonspecific_Literal_Int && ($input_type_part instanceof T_Literal_Int || $input_type_part instanceof T_Nonspecific_Literal_Int)) {
            return true;
        }
        if ($container_type_part instanceof T_Nonspecific_Literal_Int) {
            if ($input_type_part instanceof T_Int) {
                if ($atomic_comparison_result) {
                    $atomic_comparison_result->type_coerced = true;
                }
            }
            return false;
        }
        if ($input_type_part instanceof T_Callable_String) {
            if ($container_type_part::class === T_Non_Empty_String::class || $container_type_part::class === T_Non_Falsy_String::class) {
                return true;
            }
            if ($container_type_part::class === T_Lowercase_String::class || $container_type_part::class === T_Single_Letter::class) {
                if ($atomic_comparison_result) {
                    $atomic_comparison_result->type_coerced = true;
                }
                return false;
            }
        }
        if (($container_type_part instanceof T_Lowercase_String || $container_type_part instanceof T_Non_Empty_Lowercase_String) && $input_type_part instanceof T_String) {
            if ($input_type_part instanceof T_Lowercase_String && $container_type_part instanceof T_Lowercase_String || $input_type_part instanceof T_Non_Empty_Lowercase_String && $container_type_part instanceof T_Non_Empty_Lowercase_String) {
                return true;
            }
            if ($input_type_part instanceof T_Non_Empty_Lowercase_String && $container_type_part instanceof T_Lowercase_String) {
                return true;
            }
            if ($input_type_part instanceof T_Lowercase_String && $container_type_part instanceof T_Non_Empty_Lowercase_String) {
                if ($atomic_comparison_result) {
                    $atomic_comparison_result->type_coerced = true;
                }
                return false;
            }
            if ($input_type_part instanceof T_Literal_String) {
                if (strtolower($input_type_part->value) === $input_type_part->value) {
                    return $input_type_part->value || $container_type_part instanceof T_Lowercase_String;
                }
                return false;
            }
            if ($input_type_part instanceof T_Class_String) {
                return false;
            }
            if ($atomic_comparison_result) {
                $atomic_comparison_result->type_coerced = true;
            }
            return false;
        }
        if ($container_type_part instanceof T_Dependent_Get_Class) {
            $first_type = $container_type_part->as_type->get_single_atomic();
            $container_type_part = new T_Class_String('object', $first_type instanceof T_Named_Object ? $first_type : null);
        }
        if ($input_type_part instanceof T_Dependent_Get_Class) {
            $first_type = $input_type_part->as_type->get_single_atomic();
            if ($first_type instanceof T_Template_Param) {
                $object_type = $first_type->as->get_single_atomic();
                $input_type_part = new T_Template_Param_Class($first_type->param_name, $first_type->as->get_id(), $object_type instanceof T_Named_Object ? $object_type : null, $first_type->defining_class);
            } else {
                $input_type_part = new T_Class_String('object', $first_type instanceof T_Named_Object ? $first_type : null);
            }
        }
        if ($input_type_part instanceof T_Dependent_Get_Type) {
            $input_type_part = new T_String();
            if ($container_type_part instanceof T_Literal_String) {
                return isset(Class_Like_Analyzer::GETTYPE_TYPES[$container_type_part->value]);
            }
        }
        if ($container_type_part instanceof T_Dependent_Get_Debug_Type) {
            return $input_type_part instanceof T_String;
        }
        if ($input_type_part instanceof T_Dependent_Get_Debug_Type) {
            $input_type_part = new T_String();
        }
        if ($container_type_part instanceof T_Dependent_Get_Type) {
            $container_type_part = new T_String();
            if ($input_type_part instanceof T_Literal_String) {
                return isset(Class_Like_Analyzer::GETTYPE_TYPES[$input_type_part->value]);
            }
        }
        if ($input_type_part instanceof T_False && $container_type_part instanceof T_Bool && !$container_type_part instanceof T_True) {
            return true;
        }
        if ($input_type_part instanceof T_True && $container_type_part instanceof T_Bool && !$container_type_part instanceof T_False) {
            return true;
        }
        // from https://wiki.php.net/rfc/scalar_type_hints_v5:
        //
        // > int types can resolve a parameter type of float
        if ($input_type_part instanceof T_Int && $container_type_part instanceof T_Float && !$container_type_part instanceof T_Literal_Float && $allow_float_int_equality) {
            return true;
        }
        if ($container_type_part instanceof T_Array_Key && $input_type_part instanceof T_Numeric) {
            return true;
        }
        if ($container_type_part instanceof T_Array_Key && ($input_type_part instanceof T_Int || $input_type_part instanceof T_String)) {
            return true;
        }
        if ($input_type_part instanceof T_Array_Key && ($container_type_part instanceof T_Int || $container_type_part instanceof T_String)) {
            if ($atomic_comparison_result) {
                $atomic_comparison_result->type_coerced = true;
                $atomic_comparison_result->type_coerced_from_mixed = true;
                $atomic_comparison_result->scalar_type_match_found = !$container_type_part->from_docblock;
            }
            return false;
        }
        if ($container_type_part instanceof T_Scalar && $input_type_part instanceof Scalar) {
            return true;
        }
        if ($container_type_part::class === T_Float::class && $input_type_part instanceof T_Literal_Float) {
            return true;
        }
        if (($container_type_part::class === T_Non_Empty_String::class || $container_type_part::class === T_Non_Empty_Nonspecific_Literal_String::class) && $input_type_part instanceof T_Non_Falsy_String) {
            return true;
        }
        if ($container_type_part instanceof T_Non_Falsy_String && $input_type_part instanceof T_Non_Falsy_String) {
            return true;
        }
        if ($container_type_part instanceof T_Non_Falsy_String && ($input_type_part instanceof T_Non_Empty_String || $input_type_part instanceof T_Non_Empty_Nonspecific_Literal_String)) {
            if ($atomic_comparison_result) {
                $atomic_comparison_result->type_coerced = true;
            }
            return false;
        }
        if (($container_type_part instanceof T_Non_Empty_String || $container_type_part instanceof T_Non_Empty_Nonspecific_Literal_String) && $input_type_part instanceof T_Literal_String && $input_type_part->value === '') {
            return false;
        }
        if ($container_type_part instanceof T_Non_Falsy_String && $input_type_part instanceof T_Literal_String && $input_type_part->value === '0') {
            return false;
        }
        if (($container_type_part::class === T_Non_Empty_String::class || $container_type_part::class === T_Non_Falsy_String::class || $container_type_part::class === T_Single_Letter::class) && $input_type_part instanceof T_Literal_String) {
            return true;
        }
        if ($input_type_part::class === T_Int::class && $container_type_part instanceof T_Literal_Int) {
            if ($atomic_comparison_result) {
                $atomic_comparison_result->type_coerced = true;
                $atomic_comparison_result->type_coerced_from_scalar = true;
            }
            return false;
        }
        if ($input_type_part instanceof T_Int_Range && $container_type_part instanceof T_Int_Range) {
            return Integer_Range_Comparator::is_contained_by($input_type_part, $container_type_part);
        }
        if ($input_type_part instanceof T_Int && $container_type_part instanceof T_Int_Range) {
            if ($input_type_part instanceof T_Literal_Int) {
                $min_bound = $container_type_part->min_bound;
                $max_bound = $container_type_part->max_bound;
                return ($min_bound === null || $min_bound <= $input_type_part->value) && ($max_bound === null || $max_bound >= $input_type_part->value);
            }
            //any int can't be pushed inside a range without coercion (unless the range is from min to max)
            if ($container_type_part->min_bound !== null || $container_type_part->max_bound !== null) {
                if ($atomic_comparison_result) {
                    $atomic_comparison_result->type_coerced = true;
                    $atomic_comparison_result->type_coerced_from_scalar = true;
                }
            }
            return false;
        }
        if ($input_type_part::class === T_Float::class && $container_type_part instanceof T_Literal_Float) {
            if ($atomic_comparison_result) {
                $atomic_comparison_result->type_coerced = true;
                $atomic_comparison_result->type_coerced_from_scalar = true;
            }
            return false;
        }
        if (($input_type_part::class === T_String::class || $input_type_part::class === T_Single_Letter::class || $input_type_part instanceof T_Non_Empty_String || $input_type_part instanceof T_Nonspecific_Literal_String) && $container_type_part instanceof T_Literal_String) {
            if ($atomic_comparison_result) {
                $atomic_comparison_result->type_coerced = true;
                $atomic_comparison_result->type_coerced_from_scalar = true;
            }
            return false;
        }
        if (($input_type_part instanceof T_Lowercase_String || $input_type_part instanceof T_Non_Empty_Lowercase_String) && $container_type_part instanceof T_Literal_String && strtolower($container_type_part->value) === $container_type_part->value) {
            if ($atomic_comparison_result && $container_type_part->value) {
                $atomic_comparison_result->type_coerced = true;
                $atomic_comparison_result->type_coerced_from_scalar = true;
            }
            return false;
        }
        if (($container_type_part instanceof T_Class_String || $container_type_part instanceof T_Literal_Class_String) && ($input_type_part instanceof T_Class_String || $input_type_part instanceof T_Literal_Class_String)) {
            return Class_Like_String_Comparator::is_contained_by($codebase, $input_type_part, $container_type_part, $allow_interface_equality, $atomic_comparison_result);
        }
        if ($container_type_part instanceof T_String && $input_type_part instanceof T_Trait_String) {
            return true;
        }
        if ($container_type_part instanceof T_Trait_String && ($input_type_part::class === T_String::class || $input_type_part instanceof T_Non_Empty_String || $input_type_part instanceof T_Non_Empty_Nonspecific_Literal_String)) {
            if ($atomic_comparison_result) {
                $atomic_comparison_result->type_coerced = true;
            }
            return false;
        }
        if (($input_type_part instanceof T_Class_String || $input_type_part instanceof T_Literal_Class_String) && ($container_type_part::class === T_Single_Letter::class || $container_type_part::class === T_Non_Empty_String::class || $container_type_part::class === T_Non_Falsy_String::class)) {
            return true;
        }
        if ($input_type_part instanceof T_Numeric_String && $container_type_part::class === T_Non_Empty_String::class) {
            return true;
        }
        if ($container_type_part instanceof T_String && $input_type_part instanceof T_Numeric_String) {
            if ($container_type_part instanceof T_Literal_String) {
                if (is_numeric($container_type_part->value) && $atomic_comparison_result) {
                    $atomic_comparison_result->type_coerced = true;
                }
                return false;
            }
            return true;
        }
        if ($input_type_part instanceof T_String && $container_type_part instanceof T_Numeric_String) {
            if ($input_type_part instanceof T_Literal_String) {
                return is_numeric($input_type_part->value);
            }
            if ($atomic_comparison_result) {
                $atomic_comparison_result->type_coerced = true;
            }
            return false;
        }
        if ($container_type_part instanceof T_Callable_String && $input_type_part instanceof T_Literal_String) {
            $input_callable = Callable_Type_Comparator::get_callable_from_atomic($codebase, $input_type_part);
            $container_callable = Callable_Type_Comparator::get_callable_from_atomic($codebase, $container_type_part);
            if ($input_callable && $container_callable) {
                if (Callable_Type_Comparator::is_contained_by($codebase, $input_callable, $container_callable, $atomic_comparison_result ?? new Type_Comparison_Result()) === false) {
                    return false;
                }
            }
            if (!$input_callable) {
                //we could not find a callable for the input type, so the input is not contained in the container
                return false;
            }
            return true;
        }
        if ($input_type_part instanceof T_Lowercase_String && $container_type_part::class === T_Non_Empty_String::class) {
            return false;
        }
        if ($input_type_part->get_key() === $container_type_part->get_key()) {
            return true;
        }
        if (($container_type_part instanceof T_Class_String || $container_type_part instanceof T_Literal_Class_String || $container_type_part instanceof T_Callable_String) && $input_type_part instanceof T_String) {
            if ($atomic_comparison_result) {
                $atomic_comparison_result->type_coerced = true;
            }
            return false;
        }
        if ($container_type_part instanceof T_Numeric && $input_type_part->is_numeric_type()) {
            return true;
        }
        if ($input_type_part instanceof T_Numeric) {
            if ($container_type_part->is_numeric_type()) {
                if ($atomic_comparison_result) {
                    $atomic_comparison_result->type_coerced = true;
                    $atomic_comparison_result->scalar_type_match_found = !$container_type_part->from_docblock;
                }
            }
        }
        if (!$container_type_part instanceof T_Literal_Int && !$container_type_part instanceof T_Literal_String && !$container_type_part instanceof T_Literal_Float) {
            if ($atomic_comparison_result) {
                $atomic_comparison_result->type_coerced = $atomic_comparison_result->type_coerced_from_scalar = $input_type_part instanceof T_Scalar;
                $atomic_comparison_result->scalar_type_match_found = !$container_type_part->from_docblock;
            }
        }
        return false;
    }
}