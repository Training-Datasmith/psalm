<?php

declare (strict_types=1);
namespace Psalm\Internal\Type\Comparator;

use Psalm\Codebase;
use Psalm\Type\Atomic\Scalar;
use Psalm\Type\Atomic\T_Class_String;
use Psalm\Type\Atomic\T_Literal_Class_String;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Template_Param_Class;
/**
 * @internal
 */
final class Class_Like_String_Comparator
{
    /**
     * @param TClassString|TLiteralClassString $input_type_part
     * @param TClassString|TLiteralClassString $container_type_part
     */
    public static function is_contained_by(Codebase $codebase, Scalar $input_type_part, Scalar $container_type_part, bool $allow_interface_equality, ?Type_Comparison_Result $atomic_comparison_result = null): bool
    {
        if ($container_type_part instanceof T_Literal_Class_String && $input_type_part instanceof T_Literal_Class_String) {
            return $container_type_part->value === $input_type_part->value;
        }
        if ($container_type_part instanceof T_Template_Param_Class && $input_type_part::class === T_Class_String::class) {
            if ($atomic_comparison_result) {
                $atomic_comparison_result->type_coerced = true;
            }
            return false;
        }
        if ($container_type_part instanceof T_Class_String && $container_type_part->as === 'object' && !$container_type_part->as_type) {
            return true;
        }
        if ($input_type_part instanceof T_Class_String && $input_type_part->as === 'object' && !$input_type_part->as_type) {
            if ($atomic_comparison_result) {
                $atomic_comparison_result->type_coerced = true;
                $atomic_comparison_result->type_coerced_from_scalar = true;
            }
            return false;
        }
        $fake_container_object = $container_type_part instanceof T_Class_String && $container_type_part->as_type ? $container_type_part->as_type : new T_Named_Object($container_type_part instanceof T_Class_String ? $container_type_part->as : $container_type_part->value);
        $fake_input_object = $input_type_part instanceof T_Class_String && $input_type_part->as_type ? $input_type_part->as_type : new T_Named_Object($input_type_part instanceof T_Class_String ? $input_type_part->as : $input_type_part->value);
        $is_contained_by = Atomic_Type_Comparator::is_contained_by($codebase, $fake_input_object, $fake_container_object, $allow_interface_equality, false, $atomic_comparison_result);
        if ($atomic_comparison_result && $atomic_comparison_result->replacement_atomic_type instanceof T_Named_Object) {
            $atomic_comparison_result->replacement_atomic_type = new T_Class_String('object', $atomic_comparison_result->replacement_atomic_type);
        }
        return $is_contained_by;
    }
}