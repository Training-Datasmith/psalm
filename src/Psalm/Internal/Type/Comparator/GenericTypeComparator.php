<?php

declare (strict_types=1);
namespace Psalm\Internal\Type\Comparator;

use Psalm\Codebase;
use Psalm\Internal\Type\Template_Standin_Type_Replacer;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\T_Generic_Object;
use Psalm\Type\Atomic\T_Iterable;
use Psalm\Type\Atomic\T_Named_Object;
/**
 * @internal
 */
final class Generic_Type_Comparator
{
    /**
     * @param TGenericObject|TIterable $container_type_part
     */
    public static function is_contained_by(Codebase $codebase, Atomic $input_type_part, Atomic $container_type_part, bool $allow_interface_equality = false, ?Type_Comparison_Result $atomic_comparison_result = null): bool
    {
        $all_types_contain = true;
        $container_was_iterable = false;
        if ($container_type_part instanceof T_Iterable && !$container_type_part->extra_types && !$input_type_part instanceof T_Iterable) {
            $container_type_part = new T_Generic_Object('Traversable', $container_type_part->type_params);
            $container_was_iterable = true;
        }
        if (!$input_type_part instanceof T_Named_Object && !$input_type_part instanceof T_Iterable) {
            if ($atomic_comparison_result) {
                $atomic_comparison_result->type_coerced = true;
                $atomic_comparison_result->type_coerced_from_mixed = true;
            }
            return false;
        }
        $container_type_params_covariant = [];
        $input_type_params = Template_Standin_Type_Replacer::get_mapped_generic_type_params($codebase, $input_type_part, $container_type_part, $container_type_params_covariant);
        $atomic_comparison_result_type_params = null;
        if ($atomic_comparison_result) {
            if (!$atomic_comparison_result->replacement_atomic_type) {
                $atomic_comparison_result->replacement_atomic_type = $input_type_part;
            }
            if ($atomic_comparison_result->replacement_atomic_type instanceof T_Generic_Object) {
                $atomic_comparison_result_type_params = $atomic_comparison_result->replacement_atomic_type->type_params;
            }
        }
        foreach ($input_type_params as $i => $input_param) {
            if (!isset($container_type_part->type_params[$i])) {
                break;
            }
            $container_param = $container_type_part->type_params[$i];
            if ($input_param->is_never()) {
                if ($atomic_comparison_result_type_params !== null) {
                    $atomic_comparison_result_type_params[$i] = $container_param;
                }
                continue;
            }
            $param_comparison_result = new Type_Comparison_Result();
            if (!Union_Type_Comparator::is_contained_by($codebase, $input_param, $container_param, $input_param->ignore_nullable_issues, $input_param->ignore_falsable_issues, $param_comparison_result, $allow_interface_equality)) {
                if ($input_type_part->value === 'Generator' && $i === 2 && $param_comparison_result->type_coerced_from_mixed) {
                    continue;
                }
                if ($atomic_comparison_result) {
                    $atomic_comparison_result->type_coerced = $param_comparison_result->type_coerced === true && $atomic_comparison_result->type_coerced !== false;
                    $atomic_comparison_result->type_coerced_from_mixed = $param_comparison_result->type_coerced_from_mixed === true && $atomic_comparison_result->type_coerced_from_mixed !== false;
                    $atomic_comparison_result->type_coerced_from_as_mixed = !$container_was_iterable && $param_comparison_result->type_coerced_from_as_mixed === true && $atomic_comparison_result->type_coerced_from_as_mixed !== false;
                    $atomic_comparison_result->to_string_cast = $param_comparison_result->to_string_cast === true && $atomic_comparison_result->to_string_cast !== false;
                    $atomic_comparison_result->type_coerced_from_scalar = $param_comparison_result->type_coerced_from_scalar === true && $atomic_comparison_result->type_coerced_from_scalar !== false;
                    $atomic_comparison_result->scalar_type_match_found = $param_comparison_result->scalar_type_match_found === true && $atomic_comparison_result->scalar_type_match_found !== false;
                }
                // if the container was an iterable then there was no mapping
                // from a template type
                if ($container_was_iterable || !$param_comparison_result->type_coerced_from_as_mixed) {
                    $all_types_contain = false;
                }
            } elseif (!$input_type_part instanceof T_Iterable && !$container_type_part instanceof T_Iterable && !$container_param->has_template() && !$input_param->has_template()) {
                if ($input_param->contains_any_literal()) {
                    if ($atomic_comparison_result_type_params !== null) {
                        $atomic_comparison_result_type_params[$i] = $container_param;
                    }
                } else if (!($container_type_params_covariant[$i] ?? false) && !$container_param->had_template) {
                    // Make sure types are basically the same
                    if (!Union_Type_Comparator::is_contained_by($codebase, $container_param, $input_param, $container_param->ignore_nullable_issues, $container_param->ignore_falsable_issues, $param_comparison_result, $allow_interface_equality) || $param_comparison_result->type_coerced) {
                        if ($container_param->has_static_object() && $input_param->is_static_object()) {
                            // do nothing
                        } else {
                            $all_types_contain = false;
                            if ($atomic_comparison_result) {
                                $atomic_comparison_result->type_coerced = false;
                            }
                        }
                    }
                }
            }
        }
        if ($atomic_comparison_result && $atomic_comparison_result->replacement_atomic_type instanceof T_Generic_Object && $atomic_comparison_result_type_params) {
            /** @psalm-suppress ArgumentTypeCoercion Psalm bug */
            $atomic_comparison_result->replacement_atomic_type = $atomic_comparison_result->replacement_atomic_type->set_type_params($atomic_comparison_result_type_params);
        }
        if ($all_types_contain) {
            if ($atomic_comparison_result) {
                $atomic_comparison_result->to_string_cast = false;
            }
            return true;
        }
        return false;
    }
}