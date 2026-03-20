<?php

declare (strict_types=1);
namespace Psalm\Internal\Type\Comparator;

use Psalm\Codebase;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\T_Callable_Object;
use Psalm\Type\Atomic\T_Iterable;
use Psalm\Type\Atomic\T_Mixed;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Object_With_Properties;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Union;
use function count;
use function current;
use function in_array;
use function str_starts_with;
use function strpos;
use function strtolower;
use function substr;
/**
 * @internal
 */
final class Object_Comparator
{
    /**
     * @param  TNamedObject|TTemplateParam|TIterable  $input_type_part
     * @param  TNamedObject|TTemplateParam|TIterable  $container_type_part
     */
    public static function is_shallowly_contained_by(Codebase $codebase, Atomic $input_type_part, Atomic $container_type_part, bool $allow_interface_equality, ?Type_Comparison_Result $atomic_comparison_result): bool
    {
        if ($container_type_part instanceof T_Template_Param && $input_type_part instanceof T_Template_Param && $container_type_part->defining_class != $input_type_part->defining_class && 1 == count($container_type_part->as->get_atomic_types()) && 1 == count($input_type_part->as->get_atomic_types())) {
            $container_defined_in_function = str_starts_with($container_type_part->defining_class, 'fn-');
            $input_defined_in_function = str_starts_with($input_type_part->defining_class, 'fn-');
            if ($input_defined_in_function) {
                $separator_pos = strpos($input_type_part->defining_class, '::');
                if ($separator_pos === false) {
                    // Is that possible ? Falling back to default definition.
                    $input_defining_class = $input_type_part->defining_class;
                } else {
                    $input_defining_class = substr($input_type_part->defining_class, 3, $separator_pos - 3);
                }
            } else {
                $input_defining_class = $input_type_part->defining_class;
            }
            // FIXME Missing analysis for additional cases, for example :
            // - input from a parameter in a static function that is defined in the container class
            // - input and container are both defined on function parameters
            if (!$input_defined_in_function && !$container_defined_in_function || $input_defined_in_function && !$container_defined_in_function && strtolower($input_defining_class) != strtolower($container_type_part->defining_class)) {
                $container_as = current($container_type_part->as->get_atomic_types());
                $input_as = current($input_type_part->as->get_atomic_types());
                if ($container_as instanceof T_Named_Object && $input_as instanceof T_Named_Object) {
                    return self::is_shallowly_contained_by($codebase, $input_as, $container_as, $allow_interface_equality, $atomic_comparison_result);
                }
                if ($container_as instanceof T_Mixed && $input_as instanceof T_Mixed) {
                    return true;
                }
            }
        }
        $intersection_input_types = self::get_intersection_types($input_type_part);
        $intersection_container_types = self::get_intersection_types($container_type_part);
        foreach ($intersection_container_types as $intersection_container_type) {
            $container_was_static = false;
            if ($intersection_container_type instanceof T_Iterable) {
                $intersection_container_type_lower = 'iterable';
            } elseif ($intersection_container_type instanceof T_Object_With_Properties) {
                $intersection_container_type_lower = 'object';
            } elseif ($intersection_container_type instanceof T_Template_Param) {
                $intersection_container_type_lower = null;
            } elseif ($intersection_container_type instanceof T_Callable_Object) {
                $intersection_container_type_lower = 'callable-object';
            } else {
                $container_was_static = $intersection_container_type->is_static;
                $intersection_container_type_lower = strtolower($codebase->classlikes->get_un_aliased_name($intersection_container_type->value));
            }
            $any_inputs_contained = false;
            $container_type_is_interface = $intersection_container_type_lower && $codebase->interface_exists($intersection_container_type_lower);
            foreach ($intersection_input_types as $input_type_key => $intersection_input_type) {
                if ($allow_interface_equality && $container_type_is_interface && !isset($intersection_container_types[$input_type_key])) {
                    $any_inputs_contained = true;
                } elseif (self::is_intersection_shallowly_contained_by($codebase, $intersection_input_type, $intersection_container_type, $intersection_container_type_lower, $container_was_static, $allow_interface_equality, $atomic_comparison_result)) {
                    $any_inputs_contained = true;
                }
            }
            if (!$any_inputs_contained) {
                return false;
            }
        }
        return true;
    }
    /**
     * @param  TNamedObject|TTemplateParam|TIterable  $type_part
     * @return array<string, TNamedObject|TTemplateParam|TIterable|TObjectWithProperties|TCallableObject>
     */
    private static function get_intersection_types(Atomic $type_part): array
    {
        if (!$type_part->extra_types) {
            if ($type_part instanceof T_Template_Param) {
                $intersection_types = [];
                foreach ($type_part->as->get_atomic_types() as $as_atomic_type) {
                    // T1 as T2 as object becomes (T1 as object) & (T2 as object)
                    if ($as_atomic_type instanceof T_Template_Param) {
                        $intersection_types += self::get_intersection_types($as_atomic_type);
                        $type_part = $type_part->replace_as($as_atomic_type->as);
                        $intersection_types[$type_part->get_key()] = $type_part;
                        return $intersection_types;
                    }
                }
            }
            return [$type_part->get_key() => $type_part];
        }
        $extra_types = $type_part->extra_types;
        $type_part = $type_part->set_intersection_types([]);
        $extra_types[$type_part->get_key()] = $type_part;
        return $extra_types;
    }
    /**
     * @param  TNamedObject|TTemplateParam|TIterable|TObjectWithProperties|TCallableObject  $intersection_input_type
     * @param  TNamedObject|TTemplateParam|TIterable|TObjectWithProperties|TCallableObject  $intersection_container_type
     */
    private static function is_intersection_shallowly_contained_by(Codebase $codebase, Atomic $intersection_input_type, Atomic $intersection_container_type, ?string $intersection_container_type_lower, bool $container_was_static, bool $allow_interface_equality, ?Type_Comparison_Result $atomic_comparison_result): bool
    {
        if ($intersection_container_type instanceof T_Template_Param && $intersection_input_type instanceof T_Template_Param) {
            if (!$allow_interface_equality) {
                if (str_starts_with($intersection_container_type->defining_class, 'fn-') || str_starts_with($intersection_input_type->defining_class, 'fn-')) {
                    if (str_starts_with($intersection_input_type->defining_class, 'fn-') && str_starts_with($intersection_container_type->defining_class, 'fn-') && $intersection_input_type->defining_class !== $intersection_container_type->defining_class) {
                        return true;
                    }
                    foreach ($intersection_input_type->as->get_atomic_types() as $input_as_atomic) {
                        if ($input_as_atomic->equals($intersection_container_type, false)) {
                            return true;
                        }
                    }
                }
            }
            if ($intersection_container_type->param_name === $intersection_input_type->param_name && $intersection_container_type->defining_class === $intersection_input_type->defining_class) {
                return true;
            }
            if ($intersection_container_type->param_name !== $intersection_input_type->param_name || $intersection_container_type->defining_class !== $intersection_input_type->defining_class && !str_starts_with($intersection_input_type->defining_class, 'fn-') && !str_starts_with($intersection_container_type->defining_class, 'fn-')) {
                if (str_starts_with($intersection_input_type->defining_class, 'fn-') || str_starts_with($intersection_container_type->defining_class, 'fn-')) {
                    return false;
                }
                $input_class_storage = $codebase->classlike_storage_provider->get($intersection_input_type->defining_class);
                if (isset($input_class_storage->template_extended_params[$intersection_container_type->defining_class][$intersection_container_type->param_name])) {
                    return true;
                }
            }
            return false;
        }
        if ($intersection_container_type instanceof T_Template_Param || $intersection_container_type_lower === null) {
            return false;
        }
        if ($intersection_input_type instanceof T_Template_Param) {
            if ($intersection_container_type instanceof T_Named_Object && $intersection_container_type->is_static) {
                // this is extra check is redundant since we're comparing to a template as type
                $intersection_container_type = new T_Named_Object($intersection_container_type->value, false, $intersection_container_type->definite_class, $intersection_container_type->extra_types);
            }
            return Union_Type_Comparator::is_contained_by($codebase, $intersection_input_type->as, new Union([$intersection_container_type]), false, false, $atomic_comparison_result, $allow_interface_equality);
        }
        $input_was_static = false;
        if ($intersection_input_type instanceof T_Iterable) {
            $intersection_input_type_lower = 'iterable';
        } elseif ($intersection_input_type instanceof T_Object_With_Properties) {
            $intersection_input_type_lower = 'object';
        } elseif ($intersection_input_type instanceof T_Callable_Object) {
            $intersection_input_type_lower = 'callable-object';
        } else {
            $input_was_static = $intersection_input_type->is_static;
            $intersection_input_type_lower = strtolower($codebase->classlikes->get_un_aliased_name($intersection_input_type->value));
        }
        if ($intersection_container_type_lower === $intersection_input_type_lower) {
            if ($container_was_static && !$input_was_static) {
                if ($atomic_comparison_result) {
                    $atomic_comparison_result->type_coerced = true;
                }
                return false;
            }
            return true;
        }
        if ($intersection_input_type_lower === 'generator' && in_array($intersection_container_type_lower, ['iterator', 'traversable', 'iterable'], true)) {
            return true;
        }
        if ($intersection_container_type_lower === 'iterable') {
            if ($intersection_input_type_lower === 'traversable' || $codebase->classlikes->class_exists($intersection_input_type_lower) && $codebase->classlikes->class_implements($intersection_input_type_lower, 'Traversable') || $codebase->classlikes->interface_exists($intersection_input_type_lower) && $codebase->classlikes->interface_extends($intersection_input_type_lower, 'Traversable')) {
                return true;
            }
        }
        if ($intersection_input_type_lower === 'traversable' && $intersection_container_type_lower === 'iterable') {
            return true;
        }
        $input_type_is_interface = $codebase->interface_exists($intersection_input_type_lower);
        $container_type_is_interface = $codebase->interface_exists($intersection_container_type_lower);
        if ($allow_interface_equality && $container_type_is_interface && $input_type_is_interface) {
            return true;
        }
        if (($codebase->class_exists($intersection_input_type_lower) || $codebase->classlikes->enum_exists($intersection_input_type_lower)) && $codebase->class_or_interface_exists($intersection_container_type_lower) && $codebase->class_extends_or_implements($intersection_input_type_lower, $intersection_container_type_lower)) {
            if ($container_was_static && !$input_was_static) {
                if ($atomic_comparison_result) {
                    $atomic_comparison_result->type_coerced = true;
                }
                return false;
            }
            return true;
        }
        if ($input_type_is_interface && $codebase->interface_extends($intersection_input_type_lower, $intersection_container_type_lower)) {
            return true;
        }
        if (Expression_Analyzer::is_mock($intersection_input_type_lower)) {
            return true;
        }
        return false;
    }
}