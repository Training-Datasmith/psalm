<?php

declare (strict_types=1);
namespace Psalm\Internal\Type;

use InvalidArgumentException;
use Psalm\Codebase;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\Scalar;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Array_Key;
use Psalm\Type\Atomic\T_Bool;
use Psalm\Type\Atomic\T_Callable;
use Psalm\Type\Atomic\T_Callable_Keyed_Array;
use Psalm\Type\Atomic\T_Callable_Object;
use Psalm\Type\Atomic\T_Callable_String;
use Psalm\Type\Atomic\T_Class_String;
use Psalm\Type\Atomic\T_Class_String_Map;
use Psalm\Type\Atomic\T_Empty_Mixed;
use Psalm\Type\Atomic\T_Enum_Case;
use Psalm\Type\Atomic\T_False;
use Psalm\Type\Atomic\T_Float;
use Psalm\Type\Atomic\T_Generic_Object;
use Psalm\Type\Atomic\T_Int;
use Psalm\Type\Atomic\T_Int_Range;
use Psalm\Type\Atomic\T_Iterable;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Atomic\T_Literal_Class_String;
use Psalm\Type\Atomic\T_Literal_Float;
use Psalm\Type\Atomic\T_Literal_Int;
use Psalm\Type\Atomic\T_Literal_String;
use Psalm\Type\Atomic\T_Lowercase_String;
use Psalm\Type\Atomic\T_Mixed;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Never;
use Psalm\Type\Atomic\T_Non_Empty_Array;
use Psalm\Type\Atomic\T_Non_Empty_Lowercase_String;
use Psalm\Type\Atomic\T_Non_Empty_Mixed;
use Psalm\Type\Atomic\T_Non_Empty_Nonspecific_Literal_String;
use Psalm\Type\Atomic\T_Non_Empty_String;
use Psalm\Type\Atomic\T_Non_Falsy_String;
use Psalm\Type\Atomic\T_Nonspecific_Literal_Int;
use Psalm\Type\Atomic\T_Nonspecific_Literal_String;
use Psalm\Type\Atomic\T_Null;
use Psalm\Type\Atomic\T_Numeric_String;
use Psalm\Type\Atomic\T_Object;
use Psalm\Type\Atomic\T_Object_With_Properties;
use Psalm\Type\Atomic\T_Scalar;
use Psalm\Type\Atomic\T_String;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Atomic\T_Template_Param_Class;
use Psalm\Type\Atomic\T_True;
use Psalm\Type\Union;
use UnexpectedValueException;
use function array_any;
use function array_filter;
use function array_intersect_key;
use function array_key_exists;
use function array_keys;
use function array_merge;
use function array_values;
use function assert;
use function count;
use function is_int;
use function is_numeric;
use function min;
use function strpos;
use function strtolower;
use function substr;
/**
 * @internal
 */
final class Type_Combiner
{
    /**
     * Combines types together
     *  - so `int + string = int|string`
     *  - so `array<int> + array<string> = array<int|string>`
     *  - and `array<int> + string = array<int>|string`
     *  - and `array<never> + array<never> = array<never>`
     *  - and `array<string> + array<never> = array<string>`
     *  - and `array + array<string> = array<mixed>`
     *
     * @psalm-external-mutation-free
     * @psalm-suppress ImpurePropertyAssignment We're not actually mutating any external instance
     * @param  non-empty-list<Atomic>    $types
     * @param  int    $literal_limit any greater number of literal types than this
     *                               will be merged to a scalar
     */
    public static function combine(array $types, ?Codebase $codebase = null, bool $overwrite_empty_array = false, bool $allow_mixed_union = true, int $literal_limit = 500): Union
    {
        if (count($types) === 1) {
            return new Union([$types[0]]);
        }
        $combination = new Type_Combination();
        $from_docblock = false;
        foreach ($types as $type) {
            $from_docblock = $from_docblock || $type->from_docblock;
            $result = self::scrape_type_properties($type, $combination, $codebase, $overwrite_empty_array, $allow_mixed_union, $literal_limit);
            if ($result) {
                if ($from_docblock) {
                    return $result->set_properties(['from_docblock' => true]);
                }
                return $result;
            }
        }
        if (count($combination->value_types) === 1 && !count($combination->objectlike_entries) && (!$combination->array_type_params || $overwrite_empty_array && $combination->array_type_params[1]->is_never()) && !$combination->builtin_type_params && !$combination->object_type_params && !$combination->named_object_types && !$combination->strings && !$combination->class_string_types && !$combination->ints && !$combination->floats) {
            if (isset($combination->value_types['false'])) {
                return Type::get_false($from_docblock);
            }
            if (isset($combination->value_types['true'])) {
                return Type::get_true($from_docblock);
            }
        } elseif (isset($combination->value_types['void'])) {
            unset($combination->value_types['void']);
            // if we're merging with another type, we cannot represent it in PHP
            $from_docblock = true;
            if (!isset($combination->value_types['null'])) {
                $combination->value_types['null'] = new T_Null($from_docblock);
            }
        }
        if (isset($combination->value_types['true']) && isset($combination->value_types['false'])) {
            unset($combination->value_types['true'], $combination->value_types['false']);
            $combination->value_types['bool'] = new T_Bool($from_docblock);
        }
        if ($combination->array_type_params && (isset($combination->named_object_types['Traversable']) || isset($combination->builtin_type_params['Traversable'])) && (isset($combination->builtin_type_params['Traversable']) || isset($combination->named_object_types['Traversable']) && $combination->named_object_types['Traversable']->from_docblock) && !$combination->extra_types) {
            $array_param_types = $combination->array_type_params;
            $traversable_param_types = $combination->builtin_type_params['Traversable'] ?? [Type::get_mixed(), Type::get_mixed()];
            $combined_param_types = [];
            foreach ($array_param_types as $i => $array_param_type) {
                $combined_param_types[] = Type::combine_union_types($array_param_type, $traversable_param_types[$i]);
            }
            assert(count($combined_param_types) <= 2);
            $combination->value_types['iterable'] = new T_Iterable($combined_param_types);
            $combination->array_type_params = [];
            /**
             * @psalm-suppress PossiblyNullArrayAccess
             */
            unset($combination->value_types['array'], $combination->named_object_types['Traversable'], $combination->builtin_type_params['Traversable']);
        }
        if ($combination->empty_mixed && $combination->non_empty_mixed) {
            $combination->value_types['mixed'] = new T_Mixed((bool) $combination->mixed_from_loop_isset);
        }
        $new_types = [];
        if ($combination->objectlike_entries) {
            $new_types = self::handle_keyed_array_entries($combination, $overwrite_empty_array, $from_docblock);
        }
        if ($combination->array_type_params) {
            if (count($combination->array_type_params) !== 2) {
                throw new UnexpectedValueException('Unexpected number of parameters');
            }
            $new_types[] = self::get_array_type_from_generic_params($codebase, $combination, $overwrite_empty_array, $allow_mixed_union, $type, $combination->array_type_params, $from_docblock);
        }
        if ($combination->extra_types) {
            /** @psalm-suppress PropertyTypeCoercion */
            $combination->extra_types = self::combine(array_values($combination->extra_types), $codebase)->get_atomic_types();
        }
        foreach ($combination->builtin_type_params as $generic_type => $generic_type_params) {
            if ($generic_type === 'iterable') {
                assert(count($generic_type_params) <= 2);
                $new_types[] = new T_Iterable($generic_type_params, [], $from_docblock);
            } else {
                /** @psalm-suppress ArgumentTypeCoercion Caused by the PropertyTypeCoercion above */
                $generic_object = new T_Generic_Object($generic_type, $generic_type_params, false, false, $combination->extra_types, $from_docblock);
                $new_types[] = $generic_object;
                if ($combination->named_object_types) {
                    unset($combination->named_object_types[$generic_type]);
                }
            }
        }
        foreach ($combination->object_type_params as $generic_type => $generic_type_params) {
            $generic_type = substr($generic_type, 0, (int) strpos($generic_type, '<'));
            /** @psalm-suppress ArgumentTypeCoercion Caused by the PropertyTypeCoercion above */
            $generic_object = new T_Generic_Object($generic_type, $generic_type_params, false, $combination->object_static[$generic_type] ?? false, $combination->extra_types, $from_docblock);
            $new_types[] = $generic_object;
        }
        if ($combination->class_string_types) {
            if ($combination->strings) {
                foreach ($combination->strings as $k => $string) {
                    if ($string instanceof T_Literal_Class_String) {
                        $combination->class_string_types[$string->value] = new T_Named_Object($string->value);
                        unset($combination->strings[$k]);
                    }
                }
            }
            $has_non_specific_string = isset($combination->value_types['string']) && $combination->value_types['string']::class === T_String::class;
            if (!$has_non_specific_string) {
                $object_type = self::combine(array_values($combination->class_string_types), $codebase);
                foreach ($object_type->get_atomic_types() as $object_atomic_type) {
                    if ($object_atomic_type instanceof T_Named_Object) {
                        $class_type = new T_Class_String($object_atomic_type->value, $object_atomic_type);
                    } elseif ($object_atomic_type instanceof T_Object) {
                        $class_type = new T_Class_String();
                    } else {
                        continue;
                    }
                    $new_types[] = $class_type->set_from_docblock($from_docblock);
                }
            }
        }
        if ($combination->strings) {
            $new_types = array_merge($new_types, array_values($combination->strings));
        }
        if ($combination->ints) {
            $new_types = array_merge($new_types, array_values($combination->ints));
        }
        if ($combination->floats) {
            $new_types = array_merge($new_types, array_values($combination->floats));
        }
        if (isset($combination->value_types['string']) && isset($combination->value_types['int']) && isset($combination->value_types['bool']) && isset($combination->value_types['float'])) {
            unset($combination->value_types['string'], $combination->value_types['int'], $combination->value_types['bool'], $combination->value_types['float']);
            $combination->value_types['scalar'] = new T_Scalar();
        }
        if ($combination->named_object_types !== null) {
            foreach ($combination->value_types as $key => $atomic_type) {
                if ($atomic_type instanceof T_Enum_Case && isset($combination->named_object_types[$atomic_type->value])) {
                    unset($combination->value_types[$key]);
                }
            }
            $combination->value_types += $combination->named_object_types;
        }
        $has_never = isset($combination->value_types['never']);
        foreach ($combination->value_types as $type) {
            if ($type instanceof T_Mixed && $combination->mixed_from_loop_isset && (count($combination->value_types) > 1 + (int) $has_never || count($new_types) > (int) $has_never)) {
                continue;
            }
            if ($type instanceof T_Never && (count($combination->value_types) > 1 || count($new_types))) {
                $has_never = true;
                continue;
            }
            $new_types[] = $type->set_from_docblock($from_docblock);
        }
        if (!$new_types) {
            if (!$has_never) {
                throw new UnexpectedValueException('There should be types here');
            }
            $union_type = Type::get_never($from_docblock);
        } else {
            $union_type = new Union($new_types);
        }
        $union_properties = [];
        if ($from_docblock) {
            $union_properties['from_docblock'] = true;
        }
        if ($has_never) {
            $union_properties['explicit_never'] = true;
        }
        if ($union_properties !== []) {
            return $union_type->set_properties($union_properties);
        }
        return $union_type;
    }
    /**
     * @psalm-suppress ComplexMethod Unavoidably complex method
     */
    private static function scrape_type_properties(Atomic $type, Type_Combination $combination, ?Codebase $codebase, bool $overwrite_empty_array, bool $allow_mixed_union, int $literal_limit): ?Union
    {
        if ($type instanceof T_Mixed) {
            if ($type->from_loop_isset) {
                if ($combination->mixed_from_loop_isset === null) {
                    $combination->mixed_from_loop_isset = true;
                } else {
                    return null;
                }
            } else {
                $combination->mixed_from_loop_isset = false;
            }
            if ($type instanceof T_Non_Empty_Mixed) {
                $combination->non_empty_mixed = true;
                if ($combination->empty_mixed) {
                    return null;
                }
            } elseif ($type instanceof T_Empty_Mixed) {
                $combination->empty_mixed = true;
                if ($combination->non_empty_mixed) {
                    return null;
                }
            } else {
                $combination->empty_mixed = true;
                $combination->non_empty_mixed = true;
            }
            if (!$allow_mixed_union) {
                return Type::get_mixed($combination->mixed_from_loop_isset);
            }
        }
        // deal with false|bool => bool
        if (($type instanceof T_False || $type instanceof T_True) && isset($combination->value_types['bool'])) {
            return null;
        }
        if ($type::class === T_Bool::class && isset($combination->value_types['false'])) {
            unset($combination->value_types['false']);
        }
        if ($type::class === T_Bool::class && isset($combination->value_types['true'])) {
            unset($combination->value_types['true']);
        }
        if ($type instanceof T_Array && isset($combination->builtin_type_params['iterable'])) {
            $type_key = 'iterable';
        } elseif ($type instanceof T_Array && $type->type_params[1]->is_mixed() && isset($combination->value_types['iterable'])) {
            $type_key = 'iterable';
            $combination->builtin_type_params['iterable'] = [Type::get_mixed(), Type::get_mixed()];
        } elseif ($type instanceof T_Named_Object && $type->value === 'Traversable' && (isset($combination->builtin_type_params['iterable']) || isset($combination->value_types['iterable']))) {
            $type_key = 'iterable';
            if (!isset($combination->builtin_type_params['iterable'])) {
                $combination->builtin_type_params['iterable'] = [Type::get_mixed(), Type::get_mixed()];
            }
            if (!$type instanceof T_Generic_Object) {
                $type = new T_Generic_Object($type->value, [Type::get_mixed(), Type::get_mixed()]);
            }
        } elseif ($type instanceof T_Named_Object && ($type->value === 'Traversable' || $type->value === 'Generator')) {
            $type_key = $type->value;
        } else {
            $type_key = $type->get_key();
        }
        if ($type instanceof T_Iterable && $combination->array_type_params && ($type->has_docblock_params || $combination->array_type_params[1]->is_mixed())) {
            if (!isset($combination->builtin_type_params['iterable'])) {
                $combination->builtin_type_params['iterable'] = $combination->array_type_params;
            } else {
                foreach ($combination->array_type_params as $i => $array_type_param) {
                    $iterable_type_param = $combination->builtin_type_params['iterable'][$i];
                    /** @psalm-suppress PropertyTypeCoercion */
                    $combination->builtin_type_params['iterable'][$i] = Type::combine_union_types($iterable_type_param, $array_type_param);
                }
            }
            $combination->array_type_params = [];
        }
        if ($type instanceof T_Iterable && (isset($combination->named_object_types['Traversable']) || isset($combination->builtin_type_params['Traversable']))) {
            if (!isset($combination->builtin_type_params['iterable'])) {
                $combination->builtin_type_params['iterable'] = $combination->builtin_type_params['Traversable'] ?? [Type::get_mixed(), Type::get_mixed()];
            } elseif (isset($combination->builtin_type_params['Traversable'])) {
                foreach ($combination->builtin_type_params['Traversable'] as $i => $array_type_param) {
                    $iterable_type_param = $combination->builtin_type_params['iterable'][$i];
                    /** @psalm-suppress PropertyTypeCoercion */
                    $combination->builtin_type_params['iterable'][$i] = Type::combine_union_types($iterable_type_param, $array_type_param);
                }
            } else {
                $combination->builtin_type_params['iterable'] = [Type::get_mixed(), Type::get_mixed()];
            }
            /** @psalm-suppress PossiblyNullArrayAccess */
            unset($combination->named_object_types['Traversable'], $combination->builtin_type_params['Traversable']);
        }
        if ($type instanceof T_Named_Object || $type instanceof T_Template_Param || $type instanceof T_Iterable || $type instanceof T_Object_With_Properties) {
            if ($type->extra_types) {
                $combination->extra_types = array_merge($combination->extra_types, $type->extra_types);
            }
        }
        if ($type instanceof T_Named_Object) {
            if (array_key_exists($type->value, $combination->object_static)) {
                if ($combination->object_static[$type->value] && !$type->is_static) {
                    $combination->object_static[$type->value] = false;
                }
            } else {
                $combination->object_static[$type->value] = $type->is_static;
            }
        }
        if ($type instanceof T_Callable_Keyed_Array) {
            if (isset($combination->value_types['callable'])) {
                return null;
            }
            if ($combination->all_arrays_callable !== false) {
                $combination->all_arrays_callable = true;
            } else {
                $combination->all_arrays_callable = false;
            }
        }
        if ($type instanceof T_Array && $type_key === 'array') {
            foreach ($type->type_params as $i => $type_param) {
                // See https://github.com/vimeo/psalm/pull/9439#issuecomment-1464563015
                /** @psalm-suppress PropertyTypeCoercion */
                $combination->array_type_params[$i] = Type::combine_union_types($combination->array_type_params[$i] ?? null, $type_param, $codebase, $overwrite_empty_array);
            }
            if ($type instanceof T_Non_Empty_Array) {
                if ($combination->array_counts !== null) {
                    if ($type->count === null) {
                        $combination->array_counts = null;
                    } else {
                        $combination->array_counts[$type->count] = true;
                    }
                }
                if ($combination->array_min_counts !== null) {
                    if ($type->min_count === null) {
                        $combination->array_min_counts = null;
                    } else {
                        $combination->array_min_counts[$type->min_count] = true;
                    }
                }
                $combination->array_sometimes_filled = true;
            } else {
                $combination->array_always_filled = false;
            }
            if (!$type->is_empty_array()) {
                $combination->all_arrays_lists = false;
                $combination->all_arrays_class_string_maps = false;
            }
            $combination->all_arrays_callable = false;
            return null;
        }
        if ($type instanceof T_Class_String_Map) {
            foreach ([$type->get_standin_key_param(), $type->value_param] as $i => $type_param) {
                // See https://github.com/vimeo/psalm/pull/9439#issuecomment-1464563015
                /** @psalm-suppress PropertyTypeCoercion */
                $combination->array_type_params[$i] = Type::combine_union_types($combination->array_type_params[$i] ?? null, $type_param, $codebase, $overwrite_empty_array);
            }
            $combination->array_always_filled = false;
            if ($combination->all_arrays_class_string_maps !== false) {
                $combination->all_arrays_class_string_maps = true;
                $combination->class_string_map_names[$type->param_name] = true;
                $combination->class_string_map_as_types[(string) $type->as_type] = $type->as_type;
            }
            return null;
        }
        if ($type instanceof T_Generic_Object && ($type->value === 'Traversable' || $type->value === 'Generator') || $type instanceof T_Iterable && $type->has_docblock_params || $type instanceof T_Array && $type_key === 'iterable') {
            foreach ($type->type_params as $i => $type_param) {
                /** @psalm-suppress PropertyTypeCoercion */
                $combination->builtin_type_params[$type_key][$i] = Type::combine_union_types($combination->builtin_type_params[$type_key][$i] ?? null, $type_param, $codebase, $overwrite_empty_array);
            }
            return null;
        }
        if ($type instanceof T_Generic_Object) {
            foreach ($type->type_params as $i => $type_param) {
                /** @psalm-suppress PropertyTypeCoercion */
                $combination->object_type_params[$type_key][$i] = Type::combine_union_types($combination->object_type_params[$type_key][$i] ?? null, $type_param, $codebase, $overwrite_empty_array);
            }
            return null;
        }
        if ($type instanceof T_Keyed_Array) {
            if ($type instanceof T_Callable_Keyed_Array && isset($combination->value_types['callable'])) {
                return null;
            }
            $existing_objectlike_entries = (bool) $combination->objectlike_entries;
            $missing_entries = $combination->objectlike_entries;
            $combination->objectlike_sealed = $combination->objectlike_sealed && $type->fallback_params === null;
            $has_defined_keys = false;
            $class_strings = $type->class_strings ?? [];
            foreach ($type->properties as $candidate_property_name => $candidate_property_type) {
                $value_type = $combination->objectlike_entries[$candidate_property_name] ?? null;
                if (!$value_type) {
                    $combination->objectlike_entries[$candidate_property_name] = $candidate_property_type->set_possibly_undefined($existing_objectlike_entries || $candidate_property_type->possibly_undefined);
                } else {
                    $combination->objectlike_entries[$candidate_property_name] = Type::combine_union_types($value_type, $candidate_property_type, $codebase, $overwrite_empty_array);
                    if ((!$value_type->possibly_undefined || !$candidate_property_type->possibly_undefined) && $overwrite_empty_array) {
                        $combination->objectlike_entries[$candidate_property_name] = $combination->objectlike_entries[$candidate_property_name]->set_possibly_undefined(false);
                    }
                }
                if (!$candidate_property_type->possibly_undefined) {
                    $has_defined_keys = true;
                }
                if (($candidate_property_type->possibly_undefined || ($value_type->possibly_undefined ?? true)) && $combination->fallback_key_contains($candidate_property_name)) {
                    $combination->objectlike_entries[$candidate_property_name] = Type::combine_union_types($combination->objectlike_entries[$candidate_property_name], $combination->objectlike_value_type, $codebase, $overwrite_empty_array);
                }
                unset($missing_entries[$candidate_property_name]);
                if (is_int($candidate_property_name)) {
                    continue;
                }
                if (isset($combination->objectlike_class_string_keys[$candidate_property_name])) {
                    $combination->objectlike_class_string_keys[$candidate_property_name] = $combination->objectlike_class_string_keys[$candidate_property_name] && ($class_strings[$candidate_property_name] ?? false);
                } else {
                    $combination->objectlike_class_string_keys[$candidate_property_name] = $class_strings[$candidate_property_name] ?? false;
                }
            }
            if ($type->fallback_params) {
                $combination->objectlike_key_type = Type::combine_union_types($type->fallback_params[0], $combination->objectlike_key_type, $codebase, $overwrite_empty_array);
                $combination->objectlike_value_type = Type::combine_union_types($type->fallback_params[1], $combination->objectlike_value_type, $codebase, $overwrite_empty_array);
            }
            if (!$has_defined_keys) {
                $combination->array_always_filled = false;
            }
            if ($combination->array_counts !== null) {
                $combination->array_counts[count($type->properties)] = true;
            }
            if ($combination->array_min_counts !== null) {
                $min_prop_count = count(array_filter($type->properties, static fn(Union $p): bool => !$p->possibly_undefined));
                $combination->array_min_counts[$min_prop_count] = true;
            }
            foreach ($missing_entries as $k => $_) {
                $combination->objectlike_entries[$k] = $combination->objectlike_entries[$k]->set_possibly_undefined(true);
            }
            if ($combination->objectlike_value_type) {
                foreach ($missing_entries as $k => $_) {
                    if (!$combination->fallback_key_contains($k)) {
                        continue;
                    }
                    $combination->objectlike_entries[$k] = Type::combine_union_types($combination->objectlike_entries[$k], $combination->objectlike_value_type, $codebase, $overwrite_empty_array);
                }
            }
            if (!$type->is_list) {
                $combination->all_arrays_lists = false;
            } elseif ($combination->all_arrays_lists !== false) {
                $combination->all_arrays_lists = true;
            }
            if ($type instanceof T_Callable_Keyed_Array) {
                if ($combination->all_arrays_callable !== false) {
                    $combination->all_arrays_callable = true;
                }
            } else {
                $combination->all_arrays_callable = false;
            }
            $combination->all_arrays_class_string_maps = false;
            return null;
        }
        if ($type instanceof T_Object) {
            if ($type instanceof T_Callable_Object && isset($combination->value_types['callable'])) {
                return null;
            }
            $combination->named_object_types = null;
            $combination->value_types[$type_key] = $type;
            return null;
        }
        if ($type instanceof T_Iterable) {
            $combination->value_types[$type_key] = $type;
            return null;
        }
        if ($type instanceof T_Template_Param) {
            if (isset($combination->value_types[$type_key])) {
                /** @var TTemplateParam */
                $existing_template_type = $combination->value_types[$type_key];
                if (!$existing_template_type->as->equals($type->as)) {
                    $existing_template_type = $existing_template_type->replace_as(Type::combine_union_types($type->as, $existing_template_type->as, $codebase));
                    $combination->value_types[$type_key] = $existing_template_type;
                }
                return null;
            }
            $combination->value_types[$type_key] = $type;
            return null;
        }
        if ($type instanceof T_Named_Object) {
            if ($combination->named_object_types === null) {
                return null;
            }
            if (isset($combination->named_object_types[$type_key])) {
                return null;
            }
            if (!$codebase) {
                $combination->named_object_types[$type_key] = $type;
                return null;
            }
            if (!$codebase->classlikes->class_or_interface_or_enum_exists($type_key)) {
                // write this to the main list
                $combination->value_types[$type_key] = $type;
                return null;
            }
            $is_class = $codebase->class_exists($type_key);
            foreach ($combination->named_object_types as $key => $_) {
                if ($codebase->class_exists($key)) {
                    if ($codebase->class_extends_or_implements($key, $type_key)) {
                        unset($combination->named_object_types[$key]);
                        continue;
                    }
                    if ($is_class) {
                        if ($codebase->class_extends($type_key, $key)) {
                            return null;
                        }
                    }
                } else {
                    if ($codebase->interface_extends($key, $type_key)) {
                        unset($combination->named_object_types[$key]);
                        continue;
                    }
                    if ($is_class) {
                        if ($codebase->class_implements($type_key, $key)) {
                            return null;
                        }
                    } else if ($codebase->interface_extends($type_key, $key)) {
                        return null;
                    }
                }
            }
            $combination->named_object_types[$type_key] = $type;
            return null;
        }
        if ($type instanceof T_Scalar) {
            $combination->strings = null;
            $combination->ints = null;
            $combination->floats = null;
            unset($combination->value_types['string'], $combination->value_types['int'], $combination->value_types['bool'], $combination->value_types['true'], $combination->value_types['false'], $combination->value_types['float']);
            if (!isset($combination->value_types[$type_key]) || $combination->value_types[$type_key]->get_id() === $type->get_id()) {
                $combination->value_types[$type_key] = $type;
            } else {
                $combination->value_types[$type_key] = new T_Scalar();
            }
            return null;
        }
        if ($type instanceof Scalar && isset($combination->value_types['scalar'])) {
            return null;
        }
        if ($type instanceof T_Array_Key) {
            $combination->strings = null;
            $combination->ints = null;
            unset($combination->value_types['string'], $combination->value_types['int']);
            $combination->value_types[$type_key] = $type;
            return null;
        }
        if ($type instanceof T_String) {
            self::scrape_string_properties($type_key, $type, $combination, $codebase, $literal_limit);
            return null;
        }
        if ($type instanceof T_Int) {
            self::scrape_int_properties($type_key, $type, $combination, $literal_limit);
            return null;
        }
        if ($type instanceof T_Float) {
            if ($type instanceof T_Literal_Float) {
                if ($combination->floats !== null && count($combination->floats) < $literal_limit) {
                    $combination->floats[$type_key] = $type;
                } else {
                    $combination->floats = null;
                    $combination->value_types['float'] = new T_Float();
                }
            } else {
                $combination->floats = null;
                $combination->value_types['float'] = $type;
            }
            return null;
        }
        if ($type instanceof T_Callable && $type_key === 'callable') {
            if (($combination->value_types['string'] ?? null) instanceof T_Callable_String) {
                unset($combination->value_types['string']);
            } elseif (!empty($combination->objectlike_entries) && $combination->all_arrays_callable) {
                $combination->objectlike_entries = [];
            } elseif (isset($combination->value_types['callable-object'])) {
                unset($combination->value_types['callable-object']);
            }
        }
        $combination->value_types[$type_key] = $type;
        return null;
    }
    private static function scrape_string_properties(string $type_key, Atomic $type, Type_Combination $combination, ?Codebase $codebase, int $literal_limit): void
    {
        if ($type instanceof T_Callable_String && isset($combination->value_types['callable'])) {
            return;
        }
        if (isset($combination->value_types['array-key'])) {
            return;
        }
        if ($type instanceof T_Template_Param_Class) {
            $combination->value_types[$type_key] = $type;
        } elseif ($type instanceof T_Class_String) {
            if (!$type->as_type) {
                $combination->class_string_types['object'] = new T_Object();
            } else if (isset($combination->class_string_types[$type->as]) && $combination->class_string_types[$type->as] instanceof T_Named_Object) {
                if ($combination->class_string_types[$type->as]->extra_types === []) {
                    // do nothing, existing type is wider or the same
                } elseif ($type->as_type->extra_types === []) {
                    $combination->class_string_types[$type->as] = $type->as_type;
                } else {
                    // todo: figure out what to do with class-string<A&B>|class-string<A&C>
                    $combination->class_string_types[$type->as] = $type->as_type;
                }
            } else {
                $combination->class_string_types[$type->as] = $type->as_type;
            }
        } elseif ($type instanceof T_Literal_String) {
            if ($combination->strings !== null && count($combination->strings) < $literal_limit) {
                $combination->strings[$type_key] = $type;
            } else {
                $shared_classlikes = $codebase ? self::get_shared_types($combination, $codebase) : [];
                $combination->strings = null;
                if (isset($combination->value_types['string']) && $combination->value_types['string'] instanceof T_Numeric_String && is_numeric($type->value)) {
                    // do nothing
                } elseif (isset($combination->value_types['class-string']) && $type instanceof T_Literal_Class_String) {
                    // do nothing
                } elseif ($type instanceof T_Literal_Class_String) {
                    $type_classlikes = $codebase ? self::get_class_likes($codebase, $type->value) : [];
                    $mutual = array_intersect_key($type_classlikes, $shared_classlikes);
                    if ($mutual) {
                        $first_class = array_keys($mutual)[0];
                        $combination->class_string_types[$first_class] = new T_Named_Object($first_class);
                    } else {
                        $combination->class_string_types['object'] = new T_Object();
                    }
                } elseif (isset($combination->value_types['string']) && $combination->value_types['string'] instanceof T_Nonspecific_Literal_String) {
                    // do nothing
                } elseif (isset($combination->value_types['string']) && $combination->value_types['string'] instanceof T_Lowercase_String && strtolower($type->value) === $type->value) {
                    // do nothing
                } elseif (isset($combination->value_types['string']) && $combination->value_types['string'] instanceof T_Non_Falsy_String && $type->value) {
                    // do nothing
                } elseif (isset($combination->value_types['string']) && $combination->value_types['string'] instanceof T_Non_Falsy_String && $type->value === '0') {
                    $combination->value_types['string'] = new T_Non_Empty_String();
                } elseif (isset($combination->value_types['string']) && ($combination->value_types['string'] instanceof T_Non_Empty_String || $combination->value_types['string'] instanceof T_Non_Empty_Nonspecific_Literal_String) && $type->value !== '') {
                    // do nothing
                } else {
                    $combination->value_types['string'] = new T_String();
                }
            }
        } else {
            $type_key = 'string';
            if (!isset($combination->value_types['string'])) {
                if ($combination->strings) {
                    if ($type instanceof T_Numeric_String) {
                        $has_only_numeric_strings = true;
                        $has_only_non_empty_strings = true;
                        foreach ($combination->strings as $string_type) {
                            if (!is_numeric($string_type->value)) {
                                $has_only_numeric_strings = false;
                            }
                            if ($string_type->value === '') {
                                $has_only_non_empty_strings = false;
                            }
                        }
                        if ($has_only_numeric_strings) {
                            $combination->value_types['string'] = $type;
                        } elseif (count($combination->strings) === 1 && !$has_only_non_empty_strings) {
                            $combination->value_types['string'] = $type;
                            return;
                        } elseif ($has_only_non_empty_strings) {
                            $combination->value_types['string'] = new T_Non_Empty_String();
                        } else {
                            $combination->value_types['string'] = new T_String();
                        }
                    } elseif ($type instanceof T_Lowercase_String) {
                        $has_non_lowercase_string = false;
                        foreach ($combination->strings as $string_type) {
                            if (strtolower($string_type->value) !== $string_type->value) {
                                $has_non_lowercase_string = true;
                                break;
                            }
                        }
                        if ($has_non_lowercase_string) {
                            $combination->value_types['string'] = new T_String();
                        } else {
                            $combination->value_types['string'] = $type;
                        }
                    } elseif ($type instanceof T_Non_Falsy_String) {
                        $has_empty_string = false;
                        $has_falsy_string = false;
                        foreach ($combination->strings as $string_type) {
                            if ($string_type->value === '') {
                                $has_empty_string = true;
                                $has_falsy_string = true;
                                break;
                            }
                            if ($string_type->value === '0') {
                                $has_falsy_string = true;
                            }
                        }
                        if ($has_empty_string) {
                            $combination->value_types['string'] = new T_String();
                        } elseif ($has_falsy_string) {
                            $combination->value_types['string'] = new T_Non_Empty_String();
                        } else {
                            $combination->value_types['string'] = $type;
                        }
                    } elseif ($type instanceof T_Non_Empty_String || $type instanceof T_Non_Empty_Nonspecific_Literal_String) {
                        $has_empty_string = false;
                        foreach ($combination->strings as $string_type) {
                            if ($string_type->value === '') {
                                $has_empty_string = true;
                                break;
                            }
                        }
                        $has_non_lowercase_string = false;
                        if ($type instanceof T_Non_Empty_Lowercase_String) {
                            foreach ($combination->strings as $string_type) {
                                if (strtolower($string_type->value) !== $string_type->value) {
                                    $has_non_lowercase_string = true;
                                    break;
                                }
                            }
                        }
                        if ($has_empty_string) {
                            $combination->value_types['string'] = $type instanceof T_Non_Empty_Nonspecific_Literal_String ? new T_Nonspecific_Literal_String() : new T_String();
                        } elseif ($has_non_lowercase_string && $type::class !== T_Non_Empty_String::class) {
                            $combination->value_types['string'] = new T_Non_Empty_String();
                        } else {
                            $combination->value_types['string'] = $type;
                        }
                    } elseif ($type instanceof T_Nonspecific_Literal_String) {
                        $combination->value_types['string'] = $type;
                    } else {
                        $combination->value_types[$type_key] = new T_String();
                    }
                } else {
                    $combination->value_types[$type_key] = $type;
                }
            } elseif ($combination->value_types['string']::class !== T_String::class) {
                if ($type::class === T_String::class) {
                    $combination->value_types['string'] = $type;
                } elseif ($combination->value_types['string']::class !== $type::class) {
                    if ($type::class === T_Non_Empty_String::class && $combination->value_types['string']::class === T_Numeric_String::class) {
                        $combination->value_types['string'] = $type;
                    } elseif ($type::class === T_Numeric_String::class && $combination->value_types['string']::class === T_Non_Empty_String::class) {
                        // do nothing
                    } elseif (($type::class === T_Non_Empty_String::class || $type::class === T_Numeric_String::class) && $combination->value_types['string']::class === T_Non_Falsy_String::class) {
                        $combination->value_types['string'] = $type;
                    } elseif ($type::class === T_Non_Falsy_String::class && ($combination->value_types['string']::class === T_Non_Empty_String::class || $combination->value_types['string']::class === T_Numeric_String::class)) {
                        // do nothing
                    } elseif (($type::class === T_Non_Empty_String::class || $type::class === T_Non_Falsy_String::class) && $combination->value_types['string']::class === T_Non_Empty_Lowercase_String::class) {
                        $combination->value_types['string'] = new T_Non_Empty_String();
                    } elseif (($combination->value_types['string']::class === T_Non_Empty_String::class || $combination->value_types['string']::class === T_Non_Falsy_String::class) && $type::class === T_Non_Empty_Lowercase_String::class) {
                        $combination->value_types['string'] = new T_Non_Empty_String();
                    } elseif ($type::class === T_Lowercase_String::class && $combination->value_types['string']::class === T_Non_Empty_Lowercase_String::class) {
                        $combination->value_types['string'] = $type;
                    } elseif ($combination->value_types['string']::class === T_Lowercase_String::class && $type::class === T_Non_Empty_Lowercase_String::class) {
                        //no-change
                    } elseif ($combination->value_types['string']::class === T_Non_Empty_Nonspecific_Literal_String::class && $type instanceof T_Non_Empty_String) {
                        $combination->value_types['string'] = new T_Non_Empty_String();
                    } elseif ($type::class === T_Non_Empty_Nonspecific_Literal_String::class && ($combination->value_types['string'] instanceof T_Non_Empty_String || $combination->value_types['string'] instanceof T_Nonspecific_Literal_String)) {
                        // do nothing
                    } elseif ($type::class === T_Nonspecific_Literal_String::class && $combination->value_types['string']::class === T_Non_Empty_Nonspecific_Literal_String::class) {
                        $combination->value_types['string'] = $type;
                    } else {
                        $combination->value_types['string'] = new T_String();
                    }
                }
            }
            $combination->strings = null;
        }
    }
    private static function scrape_int_properties(string $type_key, Atomic $type, Type_Combination $combination, int $literal_limit): void
    {
        if (isset($combination->value_types['array-key'])) {
            return;
        }
        if ($type instanceof T_Literal_Int) {
            if ($combination->ints !== null && count($combination->ints) < $literal_limit) {
                $combination->ints[$type_key] = $type;
            } else {
                $combination->ints[$type_key] = $type;
                $all_nonnegative = !array_any($combination->ints, static fn($int): bool => $int->value < 0);
                if (isset($combination->value_types['int'])) {
                    $current_int_type = $combination->value_types['int'];
                    if ($current_int_type instanceof T_Int_Range) {
                        $min_bound = $current_int_type->min_bound;
                        $max_bound = $current_int_type->max_bound;
                        foreach ($combination->ints as $int) {
                            if (!$current_int_type->contains($int->value)) {
                                $min_bound = T_Int_Range::get_new_lowest_bound($min_bound, $int->value);
                                $max_bound = T_Int_Range::get_new_highest_bound($max_bound, $int->value);
                            }
                        }
                        if ($min_bound !== $current_int_type->min_bound || $max_bound !== $current_int_type->max_bound) {
                            $combination->value_types['int'] = new T_Int_Range($min_bound, $max_bound);
                        }
                    }
                }
                $combination->ints = null;
                if (!isset($combination->value_types['int'])) {
                    $combination->value_types['int'] = $all_nonnegative ? new T_Int_Range(0, null) : new T_Nonspecific_Literal_Int();
                }
            }
        } else {
            if ($type instanceof T_Nonspecific_Literal_Int) {
                if ($combination->ints || !isset($combination->value_types['int'])) {
                    $combination->value_types['int'] = $type;
                } elseif (isset($combination->value_types['int']) && $combination->value_types['int']::class !== $type::class) {
                    $combination->value_types['int'] = new T_Int();
                }
            } elseif ($type instanceof T_Int_Range) {
                $min_bound = $type->min_bound;
                $max_bound = $type->max_bound;
                if ($combination->ints) {
                    foreach ($combination->ints as $int) {
                        if (!$type->contains($int->value)) {
                            $min_bound = T_Int_Range::get_new_lowest_bound($min_bound, $int->value);
                            $max_bound = T_Int_Range::get_new_highest_bound($max_bound, $int->value);
                        }
                    }
                    $type = new T_Int_Range($min_bound, $max_bound);
                    $combination->value_types['int'] = $type;
                } elseif (!isset($combination->value_types['int'])) {
                    $combination->value_types['int'] = $type;
                } else {
                    $old_type = $combination->value_types['int'];
                    if ($old_type instanceof T_Int_Range) {
                        $min_bound = T_Int_Range::get_new_lowest_bound($old_type->min_bound, $min_bound);
                        $max_bound = T_Int_Range::get_new_highest_bound($old_type->max_bound, $max_bound);
                        $type = new T_Int_Range($min_bound, $max_bound);
                    } else {
                        $type = new T_Int();
                    }
                    $combination->value_types['int'] = $type;
                }
            } else {
                $combination->value_types['int'] = $type;
            }
            $combination->ints = null;
        }
    }
    /**
     * @return array<string, bool>
     */
    private static function get_shared_types(Type_Combination $combination, Codebase $codebase): array
    {
        /** @var array<string, bool>|null */
        $shared_classlikes = null;
        if ($combination->strings) {
            foreach ($combination->strings as $string_type) {
                $classlikes = self::get_class_likes($codebase, $string_type->value);
                if ($shared_classlikes === null) {
                    $shared_classlikes = $classlikes;
                } elseif ($shared_classlikes) {
                    $shared_classlikes = array_intersect_key($shared_classlikes, $classlikes);
                }
            }
        }
        if ($combination->class_string_types) {
            foreach ($combination->class_string_types as $value_type) {
                if ($value_type instanceof T_Named_Object) {
                    $classlikes = self::get_class_likes($codebase, $value_type->value);
                    if ($shared_classlikes === null) {
                        $shared_classlikes = $classlikes;
                    } elseif ($shared_classlikes) {
                        $shared_classlikes = array_intersect_key($shared_classlikes, $classlikes);
                    }
                }
            }
        }
        return $shared_classlikes ?: [];
    }
    /**
     * @return array<string, true>
     */
    private static function get_class_likes(Codebase $codebase, string $fq_classlike_name): array
    {
        try {
            $class_storage = $codebase->classlike_storage_provider->get($fq_classlike_name);
        } catch (InvalidArgumentException) {
            return [];
        }
        $classlikes = [];
        $classlikes[$fq_classlike_name] = true;
        foreach ($class_storage->parent_classes as $parent_class) {
            $classlikes[$parent_class] = true;
        }
        foreach ($class_storage->parent_interfaces as $parent_interface) {
            $classlikes[$parent_interface] = true;
        }
        foreach ($class_storage->class_implements as $interface) {
            $classlikes[$interface] = true;
        }
        return $classlikes;
    }
    /**
     * @return list<Atomic>
     */
    private static function handle_keyed_array_entries(Type_Combination $combination, bool $overwrite_empty_array, bool $from_docblock): array
    {
        $new_types = [];
        if ($combination->array_type_params && $combination->array_type_params[0]->all_string_literals() && $combination->array_always_filled) {
            foreach ($combination->array_type_params[0]->get_atomic_types() as $atomic_key_type) {
                if ($atomic_key_type instanceof T_Literal_String) {
                    $combination->objectlike_entries[$atomic_key_type->value] = $combination->array_type_params[1];
                }
            }
            $combination->array_type_params = [];
            $combination->objectlike_sealed = false;
        }
        if (!$combination->array_type_params || $combination->array_type_params[1]->is_never()) {
            if (!$overwrite_empty_array && $combination->array_type_params) {
                foreach ($combination->objectlike_entries as &$objectlike_entry) {
                    $objectlike_entry = $objectlike_entry->set_possibly_undefined(true);
                }
                unset($objectlike_entry);
            }
            if ($combination->objectlike_value_type && $combination->objectlike_value_type->is_mixed() && $combination->array_type_params && !$combination->array_type_params[1]->is_never()) {
                $combination->objectlike_entries = array_filter($combination->objectlike_entries, static fn(Union $type): bool => !$type->possibly_undefined);
            }
            if ($combination->objectlike_entries) {
                $fallback_key_type = null;
                if ($combination->objectlike_key_type) {
                    $fallback_key_type = $combination->objectlike_key_type;
                } elseif ($combination->array_type_params && $combination->array_type_params[0]->is_array_key()) {
                    $fallback_key_type = $combination->array_type_params[0];
                }
                $fallback_value_type = null;
                if ($combination->objectlike_value_type) {
                    $fallback_value_type = $combination->objectlike_value_type;
                } elseif ($combination->array_type_params && $combination->array_type_params[1]->is_mixed()) {
                    $fallback_value_type = $combination->array_type_params[1];
                }
                $sealed = $combination->objectlike_sealed && (!$combination->array_type_params || isset($combination->array_type_params[1]) && $combination->array_type_params[1]->is_never());
                if ($combination->all_arrays_callable) {
                    $objectlike = new T_Callable_Keyed_Array($combination->objectlike_entries, null, $sealed || $fallback_key_type === null || $fallback_value_type === null ? null : [$fallback_key_type, $fallback_value_type], $from_docblock);
                } else {
                    $objectlike = new T_Keyed_Array($combination->objectlike_entries, array_filter($combination->objectlike_class_string_keys), $sealed || $fallback_key_type === null || $fallback_value_type === null ? null : [$fallback_key_type, $fallback_value_type], (bool) $combination->all_arrays_lists, $from_docblock);
                }
                $new_types[] = $objectlike;
            } else {
                $key_type = $combination->objectlike_key_type ?? Type::get_array_key();
                $value_type = $combination->objectlike_value_type ?? Type::get_mixed();
                if ($combination->array_always_filled) {
                    $array_type = new T_Non_Empty_Array([$key_type, $value_type]);
                } else {
                    $array_type = new T_Array([$key_type, $value_type]);
                }
                $new_types[] = $array_type->set_from_docblock($from_docblock);
            }
            // if we're merging an empty array with an object-like, clobber empty array
            $combination->array_type_params = [];
        }
        return $new_types;
    }
    /**
     * @param  array{Union, Union}  $generic_type_params
     */
    private static function get_array_type_from_generic_params(?Codebase $codebase, Type_Combination $combination, bool $overwrite_empty_array, bool $allow_mixed_union, Atomic $type, array $generic_type_params, bool $from_docblock): Atomic
    {
        if ($combination->objectlike_entries) {
            $objectlike_generic_type = null;
            $objectlike_keys = [];
            foreach ($combination->objectlike_entries as $property_name => $property_type) {
                $objectlike_generic_type = Type::combine_union_types($property_type, $objectlike_generic_type, $codebase, $overwrite_empty_array, true, 500, false);
                if (is_int($property_name)) {
                    $objectlike_keys[$property_name] = new T_Literal_Int($property_name, $from_docblock);
                } elseif ($type instanceof T_Keyed_Array && isset($type->class_strings[$property_name])) {
                    $objectlike_keys[$property_name] = new T_Literal_Class_String($property_name, $from_docblock);
                } else {
                    $objectlike_keys[$property_name] = Type::get_atomic_string_from_literal($property_name, $from_docblock);
                }
            }
            if ($combination->objectlike_value_type) {
                $objectlike_generic_type = Type::combine_union_types($combination->objectlike_value_type, $objectlike_generic_type, $codebase, $overwrite_empty_array, true, 500, false);
            }
            $objectlike_key_type = new Union(array_values($objectlike_keys));
            $objectlike_key_type = Type::combine_union_types($combination->objectlike_key_type, $objectlike_key_type, $codebase, $overwrite_empty_array);
            $generic_type_params[0] = Type::combine_union_types($generic_type_params[0], $objectlike_key_type, $codebase, $overwrite_empty_array, $allow_mixed_union);
            if (!$generic_type_params[1]->is_mixed()) {
                $generic_type_params[1] = Type::combine_union_types($generic_type_params[1], $objectlike_generic_type, $codebase, false, $allow_mixed_union);
            }
        }
        if ($combination->all_arrays_callable) {
            $array_type = new T_Callable_Keyed_Array($generic_type_params);
        } elseif ($combination->array_always_filled || $combination->array_sometimes_filled && $overwrite_empty_array || $combination->objectlike_entries && $combination->objectlike_sealed && ($combination->array_min_counts[0] ?? false) !== true && $overwrite_empty_array) {
            if ($combination->all_arrays_lists) {
                if ($combination->objectlike_entries && $combination->objectlike_sealed && isset($combination->array_type_params[1])) {
                    $array_type = new T_Keyed_Array([$generic_type_params[1]], null, [Type::get_int(), $combination->array_type_params[1]], true);
                } elseif ($combination->array_counts && count($combination->array_counts) === 1) {
                    $cnt = array_keys($combination->array_counts)[0];
                    $properties = [];
                    for ($x = 0; $x < $cnt; $x++) {
                        $properties[] = $generic_type_params[1];
                    }
                    assert($properties !== []);
                    $array_type = new T_Keyed_Array($properties, null, null, true);
                } else {
                    $cnt = $combination->array_min_counts ? min(array_keys($combination->array_min_counts)) : 0;
                    $properties = [];
                    for ($x = 0; $x < $cnt; $x++) {
                        $properties[] = $generic_type_params[1];
                    }
                    if (!$properties) {
                        $properties[] = $generic_type_params[1]->set_possibly_undefined(true);
                    }
                    $array_type = new T_Keyed_Array($properties, null, [Type::get_list_key(), $generic_type_params[1]], true);
                }
            } else {
                /** @psalm-suppress ArgumentTypeCoercion */
                $array_type = new T_Non_Empty_Array($generic_type_params, $combination->array_min_counts ? min(array_keys($combination->array_min_counts)) : null);
            }
        } else if ($combination->all_arrays_class_string_maps && count($combination->class_string_map_as_types) === 1 && count($combination->class_string_map_names) === 1) {
            $array_type = new T_Class_String_Map(array_keys($combination->class_string_map_names)[0], array_values($combination->class_string_map_as_types)[0], $generic_type_params[1]);
        } elseif ($combination->all_arrays_lists) {
            $array_type = Type::get_list_atomic($generic_type_params[1]);
        } else {
            $array_type = new T_Array($generic_type_params);
        }
        return $array_type->set_from_docblock($from_docblock);
    }
}