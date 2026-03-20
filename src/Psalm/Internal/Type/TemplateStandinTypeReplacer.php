<?php

declare (strict_types=1);
namespace Psalm\Internal\Type;

use InvalidArgumentException;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Codebase\Methods;
use Psalm\Internal\Type\Comparator\Callable_Type_Comparator;
use Psalm\Internal\Type\Comparator\Keyed_Array_Comparator;
use Psalm\Internal\Type\Comparator\Union_Type_Comparator;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Callable;
use Psalm\Type\Atomic\T_Class_String;
use Psalm\Type\Atomic\T_Closure;
use Psalm\Type\Atomic\T_Dependent_Get_Class;
use Psalm\Type\Atomic\T_Generic_Object;
use Psalm\Type\Atomic\T_Iterable;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Atomic\T_Literal_Class_String;
use Psalm\Type\Atomic\T_Literal_Int;
use Psalm\Type\Atomic\T_Literal_String;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Object;
use Psalm\Type\Atomic\T_Object_With_Properties;
use Psalm\Type\Atomic\T_Properties_Of;
use Psalm\Type\Atomic\T_Template_Indexed_Access;
use Psalm\Type\Atomic\T_Template_Key_Of;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Atomic\T_Template_Param_Class;
use Psalm\Type\Atomic\T_Template_Properties_Of;
use Psalm\Type\Atomic\T_Template_Value_Of;
use Psalm\Type\Union;
use function array_fill;
use function array_filter;
use function array_keys;
use function array_merge;
use function array_search;
use function array_slice;
use function array_values;
use function count;
use function in_array;
use function reset;
use function str_starts_with;
use function strpos;
use function strtolower;
use function substr;
use function usort;
/**
 * @internal
 */
final class Template_Standin_Type_Replacer
{
    /**
     * This method fills in the values in $template_result based on how the various atomic types
     * of $union_type match up to the types inside $input_type.
     */
    public static function fill_template_result(Union $union_type, Template_Result $template_result, Codebase $codebase, ?Statements_Analyzer $statements_analyzer, ?Union $input_type, ?int $input_arg_offset = null, ?string $calling_class = null, ?string $calling_function = null, bool $replace = true, bool $add_lower_bound = false, ?string $bound_equality_classlike = null, int $depth = 1): void
    {
        self::replace($union_type, $template_result, $codebase, $statements_analyzer, $input_type, $input_arg_offset, $calling_class, $calling_function, $replace, $add_lower_bound, $bound_equality_classlike, $depth);
    }
    /**
     * This replaces template types in unions with standins (normally the template as type)
     *
     * $input_type here is normally the argument passed to a templated function or method.
     *
     * This method fills in the values in $template_result based on how the various atomic types
     * of $union_type match up to the types inside $input_type
     */
    public static function replace(Union $union_type, Template_Result $template_result, Codebase $codebase, ?Statements_Analyzer $statements_analyzer, ?Union $input_type, ?int $input_arg_offset = null, ?string $calling_class = null, ?string $calling_function = null, bool $replace = true, bool $add_lower_bound = false, ?string $bound_equality_classlike = null, int $depth = 1): Union
    {
        $atomic_types = [];
        $original_atomic_types = $union_type->get_atomic_types();
        // here we want to subtract atomic types from the input type
        // when they're also in the union type, so those shared atomic
        // types will never be inferred as part of the generic type
        if ($input_type && !$input_type->is_single()) {
            $new_input_type = $input_type->get_builder();
            foreach ($original_atomic_types as $key => $_) {
                if ($new_input_type->has_type($key)) {
                    $new_input_type->remove_type($key);
                }
            }
            if (!$new_input_type->is_union_empty()) {
                $input_type = $new_input_type->freeze();
            } else {
                return $union_type;
            }
        }
        $had_template = false;
        foreach ($original_atomic_types as $key => $atomic_type) {
            $atomic_types = [...$atomic_types, ...self::handle_atomic_standin($atomic_type, $key, $template_result, $codebase, $statements_analyzer, $input_type, $input_arg_offset, $calling_class, $calling_function, $replace, $add_lower_bound, $bound_equality_classlike, $depth, count($original_atomic_types) === 1, $had_template)];
        }
        if ($replace) {
            if (array_values($original_atomic_types) === $atomic_types) {
                return $union_type;
            }
            if (!$atomic_types) {
                return $union_type;
            }
            if (count($atomic_types) > 1) {
                return Type_Combiner::combine($atomic_types, $codebase)->set_properties(['ignore_nullable_issues' => $union_type->ignore_nullable_issues, 'ignore_falsable_issues' => $union_type->ignore_falsable_issues, 'possibly_undefined' => $union_type->possibly_undefined, 'had_template' => $had_template]);
            }
            return new Union($atomic_types, ['ignore_nullable_issues' => $union_type->ignore_nullable_issues, 'ignore_falsable_issues' => $union_type->ignore_falsable_issues, 'possibly_undefined' => $union_type->possibly_undefined, 'had_template' => $had_template]);
        }
        return $union_type;
    }
    /**
     * @return list<Atomic>
     */
    private static function handle_atomic_standin(Atomic $atomic_type, string $key, Template_Result $template_result, Codebase $codebase, ?Statements_Analyzer $statements_analyzer, ?Union $input_type, ?int $input_arg_offset, ?string $calling_class, ?string $calling_function, bool $replace, bool $add_lower_bound, ?string $bound_equality_classlike, int $depth, bool $was_single, bool &$had_template): array
    {
        if ($bracket_pos = strpos($key, '<')) {
            $key = substr($key, 0, $bracket_pos);
        }
        if ($atomic_type instanceof T_Template_Param && isset($template_result->template_types[$atomic_type->param_name][$atomic_type->defining_class])) {
            return self::handle_template_param_standin($atomic_type, $key, $input_type, $input_arg_offset, $calling_class, $calling_function, $template_result, $codebase, $statements_analyzer, $replace, $add_lower_bound, $bound_equality_classlike, $depth, $had_template);
        }
        if ($atomic_type instanceof T_Template_Param && isset($template_result->lower_bounds[$atomic_type->param_name][$atomic_type->defining_class])) {
            $most_specific_type = self::get_most_specific_type_from_bounds($template_result->lower_bounds[$atomic_type->param_name][$atomic_type->defining_class], $codebase);
            return array_values($most_specific_type->get_atomic_types());
        }
        if ($atomic_type instanceof T_Template_Param_Class && isset($template_result->template_types[$atomic_type->param_name][$atomic_type->defining_class])) {
            if ($replace) {
                return self::handle_template_param_class_standin($atomic_type, $input_type, $input_arg_offset, $calling_class, $calling_function, $template_result, $codebase, $statements_analyzer, true, $add_lower_bound, $bound_equality_classlike, $depth, $was_single);
            }
        }
        if ($atomic_type instanceof T_Template_Indexed_Access) {
            if ($replace) {
                $atomic_types = [];
                $include_first = true;
                if (isset($template_result->lower_bounds[$atomic_type->array_param_name][$atomic_type->defining_class]) && !empty($template_result->lower_bounds[$atomic_type->offset_param_name])) {
                    $array_template_type = self::get_most_specific_type_from_bounds($template_result->lower_bounds[$atomic_type->array_param_name][$atomic_type->defining_class], $codebase);
                    $offset_template_type = self::get_most_specific_type_from_bounds(array_values($template_result->lower_bounds[$atomic_type->offset_param_name])[0], $codebase);
                    if ($array_template_type->is_single() && $offset_template_type->is_single() && !$array_template_type->is_mixed() && !$offset_template_type->is_mixed()) {
                        $array_template_type = $array_template_type->get_single_atomic();
                        $offset_template_type = $offset_template_type->get_single_atomic();
                        if ($array_template_type instanceof T_Keyed_Array && ($offset_template_type instanceof T_Literal_String || $offset_template_type instanceof T_Literal_Int) && isset($array_template_type->properties[$offset_template_type->value])) {
                            $include_first = false;
                            $replacement_type = $array_template_type->properties[$offset_template_type->value];
                            foreach ($replacement_type->get_atomic_types() as $replacement_atomic_type) {
                                $atomic_types[] = $replacement_atomic_type;
                            }
                        }
                    }
                }
                if ($include_first) {
                    $atomic_types[] = $atomic_type;
                }
                return $atomic_types;
            }
            return [$atomic_type];
        }
        if ($atomic_type instanceof T_Template_Key_Of || $atomic_type instanceof T_Template_Value_Of) {
            if (!$replace) {
                return [$atomic_type];
            }
            $atomic_types = [];
            $include_first = true;
            $template_type = null;
            if (isset($template_result->lower_bounds[$atomic_type->param_name][$atomic_type->defining_class])) {
                $template_type = self::get_most_specific_type_from_bounds($template_result->lower_bounds[$atomic_type->param_name][$atomic_type->defining_class], $codebase);
            } elseif (isset($template_result->template_types[$atomic_type->param_name][$atomic_type->defining_class])) {
                $template_type = $template_result->template_types[$atomic_type->param_name][$atomic_type->defining_class];
            }
            if ($template_type) {
                foreach ($template_type->get_atomic_types() as $template_atomic) {
                    if (!$template_atomic instanceof T_Keyed_Array && !$template_atomic instanceof T_Array) {
                        return [$atomic_type];
                    }
                    if ($atomic_type instanceof T_Template_Key_Of) {
                        if ($template_atomic instanceof T_Keyed_Array) {
                            $template_atomic = $template_atomic->get_generic_key_type();
                        } else {
                            $template_atomic = $template_atomic->type_params[0];
                        }
                    } else if ($template_atomic instanceof T_Keyed_Array) {
                        $template_atomic = $template_atomic->get_generic_value_type();
                    } else {
                        $template_atomic = $template_atomic->type_params[1];
                    }
                    $include_first = false;
                    foreach ($template_atomic->get_atomic_types() as $key_atomic_type) {
                        $atomic_types[] = $key_atomic_type;
                    }
                }
            }
            if ($include_first) {
                $atomic_types[] = $atomic_type;
            }
            return $atomic_types;
        }
        if ($atomic_type instanceof T_Template_Properties_Of) {
            if (!$replace || !isset($template_result->template_types[$atomic_type->param_name][$atomic_type->defining_class])) {
                return [$atomic_type];
            }
            $template_type = $template_result->template_types[$atomic_type->param_name][$atomic_type->defining_class];
            $classlike_type = $template_type->get_single_atomic();
            if (!$classlike_type instanceof T_Named_Object) {
                return [$atomic_type];
            }
            /** @psalm-suppress ReferenceConstraintViolation Psalm bug, $atomic_type is not a reference */
            $atomic_type = new T_Properties_Of($classlike_type, $atomic_type->visibility_filter);
            return [$atomic_type];
        }
        $matching_atomic_types = [];
        if ($input_type && !$input_type->has_mixed()) {
            $matching_atomic_types = self::find_matching_atomic_types_for_template($atomic_type, $key, $codebase, $statements_analyzer, $input_type);
        }
        if (!$matching_atomic_types) {
            /** @psalm-suppress ReferenceConstraintViolation Psalm bug, $atomic_type is not a reference */
            $atomic_type = $atomic_type->replace_template_types_with_standins($template_result, $codebase, $statements_analyzer, null, $input_arg_offset, $calling_class, $calling_function, $replace, $add_lower_bound, $depth + 1);
            return [$atomic_type];
        }
        $atomic_types = [];
        foreach ($matching_atomic_types as $matching_atomic_type) {
            $atomic_types[] = $atomic_type->replace_template_types_with_standins($template_result, $codebase, $statements_analyzer, $matching_atomic_type, $input_arg_offset, $calling_class, $calling_function, $replace, $add_lower_bound, $depth + 1);
        }
        return $atomic_types;
    }
    /**
     * This method attempts to find bits of the input type (normally the argument type of a method call)
     * that match the base type (normally the param type of the method). These matches are used to infer
     * more template types
     *
     * Example: when passing `array<string|int>` to a function that expects `array<T>`, a rule in this method
     * identifies the matching atomic types for `T` as `string|int`
     *
     * @return list<Atomic>
     */
    private static function find_matching_atomic_types_for_template(Atomic $base_type, string $key, Codebase $codebase, ?Statements_Analyzer $statements_analyzer, Union $input_type): array
    {
        $matching_atomic_types = [];
        foreach ($input_type->get_atomic_types() as $input_key => $atomic_input_type) {
            if ($bracket_pos = strpos($input_key, '<')) {
                $input_key = substr($input_key, 0, $bracket_pos);
            }
            if ($input_key === $key) {
                $matching_atomic_types[$atomic_input_type->get_id()] = $atomic_input_type;
                continue;
            }
            if ($atomic_input_type instanceof T_Closure && $base_type instanceof T_Closure) {
                $matching_atomic_types[$atomic_input_type->get_id()] = $atomic_input_type;
                continue;
            }
            if ($atomic_input_type instanceof T_Callable && $base_type instanceof T_Callable) {
                $matching_atomic_types[$atomic_input_type->get_id()] = $atomic_input_type;
                continue;
            }
            if ($atomic_input_type instanceof T_Closure && $base_type instanceof T_Callable) {
                $matching_atomic_types[$atomic_input_type->get_id()] = $atomic_input_type;
                continue;
            }
            if (($atomic_input_type instanceof T_Array || $atomic_input_type instanceof T_Keyed_Array) && $key === 'iterable') {
                $matching_atomic_types[$atomic_input_type->get_id()] = $atomic_input_type;
                continue;
            }
            if (str_starts_with($input_key, $key . '&')) {
                $matching_atomic_types[$atomic_input_type->get_id()] = $atomic_input_type;
                continue;
            }
            if ($atomic_input_type instanceof T_Literal_Class_String && $base_type instanceof T_Class_String && $base_type->as_type) {
                try {
                    $classlike_storage = $codebase->classlike_storage_provider->get($atomic_input_type->value);
                    if (!empty($classlike_storage->template_extended_params[$base_type->as_type->value])) {
                        $atomic_input_type = new T_Class_String($base_type->as_type->value, new T_Generic_Object($base_type->as_type->value, array_values($classlike_storage->template_extended_params[$base_type->as_type->value])));
                        $matching_atomic_types[$atomic_input_type->get_id()] = $atomic_input_type;
                        continue;
                    }
                } catch (InvalidArgumentException) {
                    // do nothing
                }
            }
            if ($base_type instanceof T_Callable) {
                $matching_atomic_type = Callable_Type_Comparator::get_callable_from_atomic($codebase, $atomic_input_type, null, $statements_analyzer);
                if ($matching_atomic_type) {
                    $matching_atomic_types[$matching_atomic_type->get_id()] = $matching_atomic_type;
                    continue;
                }
            }
            if ($atomic_input_type instanceof T_Named_Object && ($base_type instanceof T_Named_Object || $base_type instanceof T_Iterable)) {
                if ($base_type instanceof T_Iterable) {
                    if ($atomic_input_type->value === 'Traversable') {
                        $matching_atomic_types[$atomic_input_type->get_id()] = $atomic_input_type;
                        continue;
                    }
                    $base_type = new T_Generic_Object('Traversable', $base_type->type_params);
                }
                try {
                    $classlike_storage = $codebase->classlike_storage_provider->get($atomic_input_type->value);
                    if ($atomic_input_type instanceof T_Generic_Object && isset($classlike_storage->template_extended_params[$base_type->value])) {
                        $matching_atomic_types[$atomic_input_type->get_id()] = $atomic_input_type;
                        continue;
                    }
                    if (!empty($classlike_storage->template_extended_params[$base_type->value])) {
                        $atomic_input_type = new T_Generic_Object($base_type->value, array_values($classlike_storage->template_extended_params[$base_type->value]));
                        $matching_atomic_types[$atomic_input_type->get_id()] = $atomic_input_type;
                        continue;
                    }
                    if (in_array('Traversable', $classlike_storage->class_implements) && $base_type->value === 'Iterator') {
                        $matching_atomic_types[$atomic_input_type->get_id()] = $atomic_input_type;
                        continue;
                    }
                } catch (InvalidArgumentException) {
                    // do nothing
                }
            }
            if ($atomic_input_type instanceof T_Named_Object && $base_type instanceof T_Object_With_Properties) {
                $object_with_keys = Keyed_Array_Comparator::coerce_to_object_with_properties($codebase, $atomic_input_type, $base_type);
                if ($object_with_keys) {
                    $matching_atomic_types[$object_with_keys->get_id()] = $object_with_keys;
                }
                continue;
            }
            if ($atomic_input_type instanceof T_Template_Param) {
                $matching_atomic_types = array_merge($matching_atomic_types, self::find_matching_atomic_types_for_template($base_type, $key, $codebase, $statements_analyzer, $atomic_input_type->as));
                continue;
            }
        }
        return array_values($matching_atomic_types);
    }
    /**
     * @return list<Atomic>
     */
    private static function handle_template_param_standin(T_Template_Param &$atomic_type, string $key, ?Union $input_type, ?int $input_arg_offset, ?string $calling_class, ?string $calling_function, Template_Result $template_result, Codebase $codebase, ?Statements_Analyzer $statements_analyzer, bool $replace, bool $add_lower_bound, ?string $bound_equality_classlike, int $depth, bool &$had_template): array
    {
        if ($atomic_type->defining_class === $calling_class) {
            return [$atomic_type];
        }
        $template_type = $template_result->template_types[$atomic_type->param_name][$atomic_type->defining_class];
        if ($template_type->get_id() === $key) {
            return array_values($template_type->get_atomic_types());
        }
        $replacement_type = $template_type;
        $param_name_key = $atomic_type->param_name;
        if (strpos($key, '&')) {
            $param_name_key = $key;
        }
        $extra_types = [];
        foreach ($atomic_type->extra_types as $extra_type) {
            $extra_type = self::replace(new Union([$extra_type]), $template_result, $codebase, $statements_analyzer, $input_type, $input_arg_offset, $calling_class, $calling_function, $replace, $add_lower_bound, $bound_equality_classlike, $depth + 1);
            if ($extra_type->is_single()) {
                $extra_type = $extra_type->get_single_atomic();
                if ($extra_type instanceof T_Named_Object || $extra_type instanceof T_Template_Param || $extra_type instanceof T_Iterable || $extra_type instanceof T_Object_With_Properties) {
                    $extra_types[$extra_type->get_key()] = $extra_type;
                }
            }
        }
        if ($replace) {
            $atomic_types = [];
            if ($replacement_type->has_mixed() && !$atomic_type->as->has_mixed()) {
                foreach ($atomic_type->as->get_atomic_types() as $as_atomic_type) {
                    $atomic_types[] = $as_atomic_type;
                }
            } else {
                $replacement_type = Type_Expander::expand_union($codebase, $replacement_type, $calling_class, $calling_class, null);
                if ($depth < 10) {
                    $replacement_type = self::replace($replacement_type, $template_result, $codebase, $statements_analyzer, $input_type, $input_arg_offset, $calling_class, $calling_function, true, $add_lower_bound, $bound_equality_classlike, $depth + 1);
                }
                foreach ($replacement_type->get_atomic_types() as $replacement_atomic_type) {
                    $replacements_found = false;
                    // @codingStandardsIgnoreStart
                    if ($replacement_atomic_type instanceof T_Template_Key_Of && isset($template_result->template_types[$replacement_atomic_type->param_name][$replacement_atomic_type->defining_class]) && count($template_result->lower_bounds[$atomic_type->param_name][$atomic_type->defining_class]) === 1) {
                        $keyed_template = $template_result->template_types[$replacement_atomic_type->param_name][$replacement_atomic_type->defining_class];
                        if ($keyed_template->is_single()) {
                            $keyed_template = $keyed_template->get_single_atomic();
                        }
                        if ($keyed_template instanceof T_Keyed_Array || $keyed_template instanceof T_Array) {
                            if ($keyed_template instanceof T_Keyed_Array) {
                                $key_type = $keyed_template->get_generic_key_type();
                            } else {
                                $key_type = $keyed_template->type_params[0];
                            }
                            $replacements_found = true;
                            foreach ($key_type->get_atomic_types() as $key_type_atomic) {
                                $atomic_types[] = $key_type_atomic;
                            }
                            $existing_lower_bound = reset($template_result->lower_bounds[$atomic_type->param_name][$atomic_type->defining_class]);
                            $existing_lower_bound->type = $key_type;
                        }
                    }
                    if ($replacement_atomic_type instanceof T_Template_Param && $replacement_atomic_type->defining_class !== $calling_class && $replacement_atomic_type->defining_class !== 'fn-' . $calling_function) {
                        foreach ($replacement_atomic_type->as->get_atomic_types() as $nested_type_atomic) {
                            $replacements_found = true;
                            $atomic_types[] = $nested_type_atomic;
                        }
                    }
                    // @codingStandardsIgnoreEnd
                    if (!$replacements_found) {
                        $atomic_types[] = $replacement_atomic_type;
                    }
                    $had_template = true;
                }
            }
            $matching_input_keys = [];
            $as = Type_Expander::expand_union($codebase, $atomic_type->as, $calling_class, $calling_class, null);
            $as = self::replace($as, $template_result, $codebase, $statements_analyzer, $input_type, $input_arg_offset, $calling_class, $calling_function, true, $add_lower_bound, $bound_equality_classlike, $depth + 1);
            $atomic_type = $atomic_type->replace_as($as);
            if ($input_type && !$template_result->readonly && ($atomic_type->as->is_mixed() || Union_Type_Comparator::can_be_contained_by($codebase, $input_type, $atomic_type->as, false, false, $matching_input_keys))) {
                $generic_param = $input_type->get_builder();
                if ($matching_input_keys) {
                    $generic_param_keys = array_keys($generic_param->get_atomic_types());
                    foreach ($generic_param_keys as $atomic_key) {
                        if (!isset($matching_input_keys[$atomic_key])) {
                            $generic_param->remove_type($atomic_key);
                        }
                    }
                }
                if ($add_lower_bound) {
                    return array_values($generic_param->get_atomic_types());
                }
                $generic_param->possibly_undefined = false;
                $generic_param = $generic_param->set_from_docblock()->freeze();
                if (isset($template_result->lower_bounds[$param_name_key][$atomic_type->defining_class])) {
                    $existing_lower_bounds = $template_result->lower_bounds[$param_name_key][$atomic_type->defining_class];
                    $has_matching_lower_bound = false;
                    foreach ($existing_lower_bounds as $existing_lower_bound) {
                        $existing_depth = $existing_lower_bound->appearance_depth;
                        $existing_arg_offset = $existing_lower_bound->arg_offset ?? $input_arg_offset;
                        if ($existing_depth === $depth && $input_arg_offset === $existing_arg_offset && $existing_lower_bound->type->get_id() === $generic_param->get_id() && $existing_lower_bound->equality_bound_classlike === $bound_equality_classlike) {
                            $has_matching_lower_bound = true;
                            break;
                        }
                    }
                    if (!$has_matching_lower_bound) {
                        $template_result->lower_bounds[$param_name_key][$atomic_type->defining_class][] = new Template_Bound($generic_param, $depth, $input_arg_offset, $bound_equality_classlike);
                    }
                } else {
                    $template_result->lower_bounds[$param_name_key][$atomic_type->defining_class] = [new Template_Bound($generic_param, $depth, $input_arg_offset, $bound_equality_classlike)];
                }
            }
            foreach ($atomic_types as &$t) {
                if ($t instanceof T_Named_Object || $t instanceof T_Template_Param || $t instanceof T_Iterable || $t instanceof T_Object_With_Properties) {
                    $t = $t->set_intersection_types($extra_types);
                } elseif ($t instanceof T_Object && $extra_types) {
                    $t = reset($extra_types)->set_intersection_types(array_slice($extra_types, 1));
                }
            }
            unset($t);
            return $atomic_types;
        }
        if ($add_lower_bound && $input_type && !$template_result->readonly) {
            $matching_input_keys = [];
            if (Union_Type_Comparator::can_be_contained_by($codebase, $input_type, $replacement_type, false, false, $matching_input_keys)) {
                $generic_param = $input_type->get_builder();
                if ($matching_input_keys) {
                    $generic_param_keys = array_keys($generic_param->get_atomic_types());
                    foreach ($generic_param_keys as $atomic_key) {
                        if (!isset($matching_input_keys[$atomic_key])) {
                            $generic_param->remove_type($atomic_key);
                        }
                    }
                }
                $generic_param = $generic_param->freeze();
                $upper_bound = $template_result->upper_bounds[$param_name_key][$atomic_type->defining_class] ?? null;
                if ($upper_bound) {
                    if (!Union_Type_Comparator::is_contained_by($codebase, $upper_bound->type, $generic_param) || !Union_Type_Comparator::is_contained_by($codebase, $generic_param, $upper_bound->type)) {
                        $intersection_type = Type::intersect_union_types($upper_bound->type, $generic_param, $codebase);
                    } else {
                        $intersection_type = $generic_param;
                    }
                    if ($intersection_type) {
                        $upper_bound->type = $intersection_type;
                    } else {
                        $template_result->upper_bounds_unintersectable_types[] = $upper_bound->type;
                        $template_result->upper_bounds_unintersectable_types[] = $generic_param;
                        $upper_bound->type = Type::get_mixed();
                    }
                } else {
                    $template_result->upper_bounds[$param_name_key][$atomic_type->defining_class] = new Template_Bound($generic_param);
                }
            }
        }
        return [$atomic_type];
    }
    /**
     * @return non-empty-list<TClassString>
     */
    public static function handle_template_param_class_standin(T_Template_Param_Class $atomic_type, ?Union $input_type, ?int $input_arg_offset, ?string $calling_class, ?string $calling_function, Template_Result $template_result, Codebase $codebase, ?Statements_Analyzer $statements_analyzer, bool $replace, bool $add_lower_bound, ?string $bound_equality_classlike, int $depth, bool $was_single): array
    {
        if ($atomic_type->defining_class === $calling_class) {
            return [$atomic_type];
        }
        $atomic_types = [];
        $as_type = $atomic_type->as_type;
        if ($input_type && !$template_result->readonly) {
            $valid_input_atomic_types = [];
            foreach ($input_type->get_atomic_types() as $input_atomic_type) {
                if ($input_atomic_type instanceof T_Literal_Class_String) {
                    $valid_input_atomic_types[] = new T_Named_Object($input_atomic_type->value, false, false, [], true);
                } elseif ($input_atomic_type instanceof T_Template_Param_Class) {
                    $valid_input_atomic_types[] = new T_Template_Param($input_atomic_type->param_name, $input_atomic_type->as_type ? new Union([$input_atomic_type->as_type]) : ($input_atomic_type->as === 'object' ? Type::get_object() : Type::get_mixed()), $input_atomic_type->defining_class, [], true);
                } elseif ($input_atomic_type instanceof T_Class_String) {
                    if ($input_atomic_type->as_type) {
                        $valid_input_atomic_types[] = $input_atomic_type->as_type->set_from_docblock(true);
                    } elseif ($input_atomic_type->as !== 'object') {
                        $valid_input_atomic_types[] = new T_Named_Object($input_atomic_type->as, false, false, [], true);
                    } else {
                        $valid_input_atomic_types[] = new T_Object(true);
                    }
                } elseif ($input_atomic_type instanceof T_Dependent_Get_Class) {
                    $valid_input_atomic_types[] = new T_Object(true);
                }
            }
            $generic_param = null;
            if ($valid_input_atomic_types) {
                $generic_param = new Union($valid_input_atomic_types);
            } elseif ($was_single) {
                $generic_param = Type::get_mixed();
            }
            if ($as_type) {
                // sometimes templated class-strings can contain nested templates
                // in the as type that need to be resolved as well.
                $as_type_union = self::replace(new Union([$as_type]), $template_result, $codebase, $statements_analyzer, $generic_param, $input_arg_offset, $calling_class, $calling_function, $replace, $add_lower_bound, $bound_equality_classlike, $depth + 1);
                $first = $as_type_union->get_single_atomic();
                if (count($as_type_union->get_atomic_types()) === 1 && $first instanceof T_Named_Object) {
                    $as_type = $first;
                } else {
                    $as_type = null;
                }
            }
            if ($generic_param) {
                if (isset($template_result->lower_bounds[$atomic_type->param_name][$atomic_type->defining_class])) {
                    $template_result->lower_bounds[$atomic_type->param_name][$atomic_type->defining_class] = [new Template_Bound(Type::combine_union_types($generic_param, self::get_most_specific_type_from_bounds($template_result->lower_bounds[$atomic_type->param_name][$atomic_type->defining_class], $codebase)), $depth)];
                } else {
                    $template_result->lower_bounds[$atomic_type->param_name][$atomic_type->defining_class] = [new Template_Bound($generic_param, $depth, $input_arg_offset)];
                }
            }
        } else {
            $template_type = $template_result->template_types[$atomic_type->param_name][$atomic_type->defining_class];
            foreach ($template_type->get_atomic_types() as $template_atomic_type) {
                if ($template_atomic_type instanceof T_Named_Object) {
                    $atomic_types[] = new T_Class_String($template_atomic_type->value, $template_atomic_type);
                } elseif ($template_atomic_type instanceof T_Object) {
                    $atomic_types[] = new T_Class_String();
                }
            }
        }
        $class_string = new T_Class_String($atomic_type->as, $as_type);
        if (!$atomic_types) {
            $atomic_types[] = $class_string;
        }
        return $atomic_types;
    }
    /**
     * @param  array<string, array<string, non-empty-list<TemplateBound>>>  $template_types
     */
    public static function get_root_template_type(array $template_types, string $param_name, string $defining_class, array $visited_classes, ?Codebase $codebase): ?Union
    {
        if (isset($visited_classes[$defining_class])) {
            return null;
        }
        if (isset($template_types[$param_name][$defining_class])) {
            $mapped_type = self::get_most_specific_type_from_bounds($template_types[$param_name][$defining_class], $codebase);
            $mapped_type_atomic_types = array_values($mapped_type->get_atomic_types());
            if (count($mapped_type_atomic_types) > 1 || !$mapped_type_atomic_types[0] instanceof T_Template_Param) {
                return $mapped_type;
            }
            $first_template = $mapped_type_atomic_types[0];
            return self::get_root_template_type($template_types, $first_template->param_name, $first_template->defining_class, $visited_classes + [$defining_class => true], $codebase) ?? $mapped_type;
        }
        return null;
    }
    /**
     * This takes a list of lower bounds and returns the most general type.
     *
     * If given a single bound that's just the type of that bound.
     *
     * If instead given a collection of lower bounds it normally returns a union of those
     * bound types.
     *
     * @param  non-empty-list<TemplateBound>  $lower_bounds
     */
    public static function get_most_specific_type_from_bounds(array $lower_bounds, ?Codebase $codebase): Union
    {
        if (count($lower_bounds) === 1) {
            return reset($lower_bounds)->type;
        }
        usort($lower_bounds, static fn(Template_Bound $bound_a, Template_Bound $bound_b): int => $bound_b->appearance_depth <=> $bound_a->appearance_depth);
        $current_depth = null;
        $current_type = null;
        $had_invariant = false;
        $last_arg_offset = -1;
        foreach ($lower_bounds as $template_bound) {
            if ($current_depth === null) {
                $current_depth = $template_bound->appearance_depth;
            } elseif ($current_depth !== $template_bound->appearance_depth && $current_type) {
                if (!$current_type->is_never() && ($had_invariant || $last_arg_offset === $template_bound->arg_offset)) {
                    // escape switches when matching on invariant generic params
                    // and when matching
                    break;
                }
                $current_depth = $template_bound->appearance_depth;
            }
            $had_invariant = $had_invariant ?: $template_bound->equality_bound_classlike !== null;
            $current_type = Type::combine_union_types($current_type, $template_bound->type, $codebase);
            $last_arg_offset = $template_bound->arg_offset;
        }
        return $current_type ?? Type::get_mixed();
    }
    /**
     * @param TGenericObject|TNamedObject|TIterable $input_type_part
     * @param TGenericObject|TIterable $container_type_part
     * @psalm-external-mutation-free
     * @return list<Union>
     */
    public static function get_mapped_generic_type_params(Codebase $codebase, Atomic $input_type_part, Atomic $container_type_part, ?array &$container_type_params_covariant = null): array
    {
        if ($input_type_part instanceof T_Generic_Object || $input_type_part instanceof T_Iterable) {
            $input_type_params = $input_type_part->type_params;
        } elseif ($codebase->classlike_storage_provider->has($input_type_part->value)) {
            $class_storage = $codebase->classlike_storage_provider->get($input_type_part->value);
            $container_class = $container_type_part->value;
            if (strtolower($input_type_part->value) === strtolower($container_type_part->value)) {
                $input_type_params = $class_storage->get_class_template_types();
            } elseif (!empty($class_storage->template_extended_params[$container_class])) {
                $input_type_params = array_values($class_storage->template_extended_params[$container_class]);
            } else {
                $input_type_params = array_fill(0, count($class_storage->template_types ?? []), Type::get_mixed());
            }
        } else {
            $input_type_params = [];
        }
        $input_class_storage = $codebase->classlike_storage_provider->has($input_type_part->value) ? $codebase->classlike_storage_provider->get($input_type_part->value) : null;
        $container_type_params_covariant = $codebase->classlike_storage_provider->has($container_type_part->value) ? $codebase->classlike_storage_provider->get($container_type_part->value)->template_covariants : null;
        if ($input_type_part->value !== $container_type_part->value && $input_class_storage) {
            $input_template_types = $input_class_storage->template_types;
            $i = 0;
            $replacement_templates = [];
            if ($input_template_types && (!$container_type_part instanceof T_Generic_Object || !$container_type_part->remapped_params)) {
                foreach ($input_template_types as $template_name => $_) {
                    if (!isset($input_type_params[$i])) {
                        break;
                    }
                    $replacement_templates[$template_name][$input_type_part->value] = $input_type_params[$i];
                    $i++;
                }
            }
            $template_extends = $input_class_storage->template_extended_params;
            $container_type_part_value = $container_type_part->value === 'iterable' ? 'Traversable' : $container_type_part->value;
            if (isset($template_extends[$container_type_part_value])) {
                $params = $template_extends[$container_type_part_value];
                $new_input_params = [];
                foreach ($params as $extended_input_param_type) {
                    $new_input_param = null;
                    foreach ($extended_input_param_type->get_atomic_types() as $extended_template) {
                        $extended_templates = $extended_template instanceof T_Template_Param ? array_values(array_filter(Methods::get_extended_templated_types($extended_template, $template_extends), static fn(Atomic $a): bool => $a instanceof T_Template_Param)) : [];
                        $candidate_param_types = [];
                        foreach ($extended_templates as $template) {
                            if (!isset($input_class_storage->template_types[$template->param_name][$template->defining_class])) {
                                continue;
                            }
                            $old_params_offset = (int) array_search($template->param_name, array_keys($input_class_storage->template_types), true);
                            $candidate_param_types[] = ($input_type_params[$old_params_offset] ?? Type::get_mixed())->set_properties(['from_template_default' => true]);
                        }
                        $new_input_param = Type::combine_union_types($new_input_param, $candidate_param_types ? Type::combine_union_type_array($candidate_param_types, $codebase) : new Union([$extended_template], ['from_template_default' => true]));
                    }
                    $new_input_param = Template_Inferred_Type_Replacer::replace($new_input_param, new Template_Result([], $replacement_templates), $codebase);
                    $new_input_params[] = $new_input_param;
                }
                $input_type_params = $new_input_params;
            }
        }
        return $input_type_params;
    }
}