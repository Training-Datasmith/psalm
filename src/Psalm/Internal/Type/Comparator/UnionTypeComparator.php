<?php

declare (strict_types=1);
namespace Psalm\Internal\Type\Comparator;

use Psalm\Codebase;
use Psalm\Internal\Type\Type_Expander;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\T_Array_Key;
use Psalm\Type\Atomic\T_Callable;
use Psalm\Type\Atomic\T_Class_Constant;
use Psalm\Type\Atomic\T_False;
use Psalm\Type\Atomic\T_Int_Range;
use Psalm\Type\Atomic\T_Mixed;
use Psalm\Type\Atomic\T_Null;
use Psalm\Type\Atomic\T_Numeric;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Atomic\T_Type_Alias;
use Psalm\Type\Union;
use function array_merge;
use function array_pop;
use function array_push;
use function array_reverse;
use function count;
use function is_array;
use const PHP_INT_MAX;
/**
 * @internal
 */
final class Union_Type_Comparator
{
    /**
     * Does the input param type match the given param type
     */
    public static function is_contained_by(Codebase $codebase, Union $input_type, Union $container_type, bool $ignore_null = false, bool $ignore_false = false, ?Type_Comparison_Result $union_comparison_result = null, bool $allow_interface_equality = false, bool $allow_float_int_equality = true): bool
    {
        if ($container_type->is_vanilla_mixed()) {
            return true;
        }
        if ($input_type->is_never()) {
            return true;
        }
        if ($union_comparison_result) {
            $union_comparison_result->scalar_type_match_found = true;
        }
        if ($input_type->possibly_undefined && !$input_type->possibly_undefined_from_try && !$container_type->possibly_undefined) {
            return false;
        }
        $container_has_template = $container_type->has_template_or_static();
        $input_atomic_types = array_reverse(self::get_type_parts($codebase, $input_type));
        while ($input_type_part = array_pop($input_atomic_types)) {
            if ($input_type_part instanceof T_Null && $ignore_null) {
                continue;
            }
            if ($input_type_part instanceof T_False && $ignore_false) {
                continue;
            }
            if ($input_type_part instanceof T_Template_Param && !$container_has_template && !$input_type_part->extra_types) {
                $input_atomic_types = array_merge($input_type_part->as->get_atomic_types(), $input_atomic_types);
                continue;
            }
            $type_match_found = false;
            $scalar_type_match_found = false;
            $all_to_string_cast = true;
            $all_type_coerced = null;
            $all_type_coerced_from_mixed = null;
            $all_type_coerced_from_as_mixed = null;
            $some_type_coerced = false;
            $some_type_coerced_from_mixed = false;
            $some_missing_shape_fields = null;
            if ($input_type_part instanceof T_Array_Key && ($container_type->has_int() && $container_type->has_string())) {
                continue;
            }
            if ($input_type_part instanceof T_Array_Key && $container_type->has_template()) {
                foreach ($container_type->get_template_types() as $template_type) {
                    if ($template_type->as->is_array_key()) {
                        continue 2;
                    }
                }
            }
            if ($input_type_part instanceof T_Int_Range && $container_type->has_int()) {
                if (Integer_Range_Comparator::is_contained_by_union($input_type_part, $container_type)) {
                    continue;
                }
            }
            foreach (self::get_type_parts($codebase, $container_type) as $container_type_part) {
                if ($ignore_null && $container_type_part instanceof T_Null && !$input_type_part instanceof T_Null) {
                    continue;
                }
                if ($ignore_false && $container_type_part instanceof T_False && !$input_type_part instanceof T_False) {
                    continue;
                }
                // if params are specified
                if ($container_type_part instanceof T_Callable && is_array($container_type_part->params) && $input_type_part instanceof T_Callable) {
                    $container_all_param_count = count($container_type_part->params);
                    $container_required_param_count = 0;
                    foreach ($container_type_part->params as $index => $container_param) {
                        if (!$container_param->is_optional) {
                            $container_required_param_count = $index + 1;
                        }
                        if ($container_param->is_variadic === true) {
                            $container_all_param_count = PHP_INT_MAX;
                        }
                    }
                    $input_required_param_count = 0;
                    if (!is_array($input_type_part->params)) {
                        // it's not declared, there can be an arbitrary number of params
                        $input_all_param_count = PHP_INT_MAX;
                    } else {
                        $input_all_param_count = count($input_type_part->params);
                        foreach ($input_type_part->params as $index => $input_param) {
                            // can be false or not set at all
                            if (!$input_param->is_optional) {
                                $input_required_param_count = $index + 1;
                            }
                            if ($input_param->is_variadic === true) {
                                $input_all_param_count = PHP_INT_MAX;
                            }
                        }
                    }
                    // too few or too many non-optional params provided in callback
                    if ($container_all_param_count > $input_all_param_count) {
                        continue;
                    }
                    if ($container_required_param_count > $input_all_param_count) {
                        continue;
                    }
                    if ($input_required_param_count > $container_all_param_count) {
                        continue;
                    }
                    if ($input_required_param_count > $container_required_param_count) {
                        continue;
                    }
                }
                if ($union_comparison_result) {
                    $atomic_comparison_result = new Type_Comparison_Result();
                } else {
                    $atomic_comparison_result = null;
                }
                $is_atomic_contained_by = Atomic_Type_Comparator::is_contained_by($codebase, $input_type_part, $container_type_part, $allow_interface_equality, $allow_float_int_equality, $atomic_comparison_result);
                if ($input_type_part instanceof T_Mixed && $input_type->from_template_default && $input_type->from_docblock && $atomic_comparison_result && $atomic_comparison_result->type_coerced_from_mixed) {
                    $atomic_comparison_result->type_coerced_from_as_mixed = true;
                }
                if ($atomic_comparison_result) {
                    if ($atomic_comparison_result->scalar_type_match_found !== null) {
                        $scalar_type_match_found = $atomic_comparison_result->scalar_type_match_found;
                    }
                    if ($union_comparison_result && $atomic_comparison_result->type_coerced_from_scalar !== null) {
                        $union_comparison_result->type_coerced_from_scalar = $atomic_comparison_result->type_coerced_from_scalar;
                    }
                    if ($is_atomic_contained_by && $union_comparison_result && $atomic_comparison_result->replacement_atomic_type) {
                        if (!$union_comparison_result->replacement_union_type) {
                            $union_comparison_result->replacement_union_type = $input_type;
                        }
                        $replacement = $union_comparison_result->replacement_union_type->get_builder();
                        $replacement->remove_type($input_type->get_key());
                        $replacement->add_type($atomic_comparison_result->replacement_atomic_type);
                        $union_comparison_result->replacement_union_type = $replacement->freeze();
                    }
                }
                if ($input_type_part instanceof T_Numeric && $container_type->has_string() && $container_type->has_int() && $container_type->has_float()) {
                    $scalar_type_match_found = false;
                    $is_atomic_contained_by = true;
                }
                if ($input_type_part instanceof Atomic\T_Iterable && ($container_type->has_array() || $container_type->contains_class_like('traversable'))) {
                    $scalar_type_match_found = false;
                    $is_atomic_contained_by = true;
                }
                if ($atomic_comparison_result) {
                    if ($atomic_comparison_result->type_coerced) {
                        $some_type_coerced = true;
                    }
                    if ($atomic_comparison_result->type_coerced_from_mixed) {
                        $some_type_coerced_from_mixed = true;
                    }
                    if ($atomic_comparison_result->type_coerced !== true || $all_type_coerced === false) {
                        $all_type_coerced = false;
                    } else {
                        $all_type_coerced = true;
                    }
                    if ($atomic_comparison_result->type_coerced_from_mixed !== true || $all_type_coerced_from_mixed === false) {
                        $all_type_coerced_from_mixed = false;
                    } else {
                        $all_type_coerced_from_mixed = true;
                    }
                    if ($atomic_comparison_result->type_coerced_from_as_mixed !== true || $all_type_coerced_from_as_mixed === false) {
                        $all_type_coerced_from_as_mixed = false;
                    } else {
                        $all_type_coerced_from_as_mixed = true;
                    }
                    if ($atomic_comparison_result->missing_shape_fields) {
                        $some_missing_shape_fields = $atomic_comparison_result->missing_shape_fields;
                    }
                }
                if ($is_atomic_contained_by) {
                    $type_match_found = true;
                    if ($atomic_comparison_result) {
                        if ($atomic_comparison_result->to_string_cast !== true) {
                            $all_to_string_cast = false;
                        }
                    }
                    $all_type_coerced_from_mixed = false;
                    $all_type_coerced_from_as_mixed = false;
                    $all_type_coerced = false;
                }
            }
            if ($union_comparison_result) {
                // only set this flag if we're definite that the only
                // reason the type match has been found is because there
                // was a __toString cast
                if ($all_to_string_cast && $type_match_found) {
                    $union_comparison_result->to_string_cast = true;
                }
                if ($all_type_coerced) {
                    $union_comparison_result->type_coerced = true;
                }
                if ($all_type_coerced_from_mixed) {
                    $union_comparison_result->type_coerced_from_mixed = true;
                    if ($input_type->from_template_default && $input_type->from_docblock || $all_type_coerced_from_as_mixed) {
                        $union_comparison_result->type_coerced_from_as_mixed = true;
                    }
                }
            }
            if (!$type_match_found) {
                if ($union_comparison_result) {
                    if ($some_type_coerced) {
                        $union_comparison_result->type_coerced = true;
                    }
                    if ($some_type_coerced_from_mixed) {
                        $union_comparison_result->type_coerced_from_mixed = true;
                        if ($input_type->from_template_default && $input_type->from_docblock || $all_type_coerced_from_as_mixed) {
                            $union_comparison_result->type_coerced_from_as_mixed = true;
                        }
                    }
                    if (!$scalar_type_match_found) {
                        $union_comparison_result->scalar_type_match_found = false;
                    }
                    if ($some_missing_shape_fields && !$some_type_coerced && !$scalar_type_match_found) {
                        $union_comparison_result->missing_shape_fields = $some_missing_shape_fields;
                    }
                }
                return false;
            }
        }
        return true;
    }
    /**
     * Used for comparing signature typehints, uses PHP's light contravariance rules
     */
    public static function is_contained_by_in_php(?Union $input_type, Union $container_type): bool
    {
        if ($container_type->is_mixed()) {
            return true;
        }
        if (!$input_type) {
            return false;
        }
        if ($input_type->is_never()) {
            return true;
        }
        if ($input_type->get_id() === $container_type->get_id()) {
            return true;
        }
        if ($input_type->is_nullable() && !$container_type->is_nullable()) {
            return false;
        }
        $input_type_not_null = $input_type->get_builder();
        $input_type_not_null->remove_type('null');
        $container_type_not_null = $container_type->get_builder();
        $container_type_not_null->remove_type('null');
        if ($input_type_not_null->get_id() === $container_type_not_null->get_id()) {
            return true;
        }
        if ($input_type_not_null->has_array() && $container_type_not_null->has_type('iterable')) {
            return true;
        }
        return false;
    }
    /**
     * Does the input param type match the given param type
     */
    public static function can_be_contained_by(Codebase $codebase, Union $input_type, Union $container_type, bool $ignore_null = false, bool $ignore_false = false, array &$matching_input_keys = []): bool
    {
        if ($container_type->has_mixed()) {
            return true;
        }
        if ($input_type->is_never()) {
            return true;
        }
        if ($input_type->possibly_undefined && !$container_type->possibly_undefined) {
            return false;
        }
        foreach (self::get_type_parts($codebase, $container_type) as $container_type_part) {
            if ($container_type_part instanceof T_Null && $ignore_null) {
                continue;
            }
            if ($container_type_part instanceof T_False && $ignore_false) {
                continue;
            }
            foreach (self::get_type_parts($codebase, $input_type) as $input_type_part) {
                $atomic_comparison_result = new Type_Comparison_Result();
                $is_atomic_contained_by = Atomic_Type_Comparator::is_contained_by($codebase, $input_type_part, $container_type_part, false, false, $atomic_comparison_result);
                if ($is_atomic_contained_by && !$atomic_comparison_result->to_string_cast || $atomic_comparison_result->type_coerced_from_mixed) {
                    $matching_input_keys[$input_type_part->get_key()] = true;
                }
            }
        }
        return (bool) $matching_input_keys;
    }
    /**
     * Can any part of the $type1 be equal to any part of $type2
     */
    public static function can_expression_types_be_identical(Codebase $codebase, Union $type1, Union $type2, bool $allow_interface_equality = true): bool
    {
        if ($type1->has_mixed() || $type2->has_mixed()) {
            return true;
        }
        if ($type1->is_nullable() && $type2->is_nullable()) {
            return true;
        }
        foreach (self::get_type_parts($codebase, $type1) as $type1_part) {
            foreach (self::get_type_parts($codebase, $type2) as $type2_part) {
                //special case for TIntRange because it can contain a part of another TIntRange.
                //For example int<0,10> and int<5, 15> can be identical but none contain the other
                if ($type1_part instanceof T_Int_Range && $type2_part instanceof T_Int_Range) {
                    $intersection_range = T_Int_Range::intersect_int_ranges($type1_part, $type2_part);
                    return $intersection_range !== null;
                }
                $either_contains = Atomic_Type_Comparator::can_be_identical($codebase, $type1_part, $type2_part, $allow_interface_equality);
                if ($either_contains) {
                    return true;
                }
            }
        }
        return false;
    }
    /**
     * @return list<Atomic>
     */
    private static function get_type_parts(Codebase $codebase, Union $union_type): array
    {
        $atomic_types = [];
        foreach ($union_type->get_atomic_types() as $atomic_type) {
            if (!$atomic_type instanceof T_Type_Alias && !$atomic_type instanceof T_Class_Constant) {
                $atomic_types[] = $atomic_type;
                continue;
            }
            if ($atomic_type instanceof T_Type_Alias) {
                $fq_classlike_name = $atomic_type->declaring_fq_classlike_name;
            } else {
                $fq_classlike_name = $atomic_type->fq_classlike_name;
            }
            $expanded = Type_Expander::expand_atomic($codebase, $atomic_type, $fq_classlike_name, $fq_classlike_name, null, true, true);
            array_push($atomic_types, ...$expanded);
        }
        return $atomic_types;
    }
}