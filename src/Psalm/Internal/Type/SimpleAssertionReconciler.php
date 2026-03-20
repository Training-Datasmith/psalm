<?php

declare (strict_types=1);
namespace Psalm\Internal\Type;

use AssertionError;
use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\Internal\Codebase\Class_Constant_By_Wildcard_Resolver;
use Psalm\Internal\Codebase\Internal_Call_Map_Handler;
use Psalm\Internal\Type\Comparator\Callable_Type_Comparator;
use Psalm\Storage\Assertion;
use Psalm\Storage\Assertion\Any;
use Psalm\Storage\Assertion\Array_Key_Exists;
use Psalm\Storage\Assertion\Has_Array_Key;
use Psalm\Storage\Assertion\Has_At_Least_Count;
use Psalm\Storage\Assertion\Has_Exact_Count;
use Psalm\Storage\Assertion\Has_Int_Or_String_Array_Access;
use Psalm\Storage\Assertion\Has_Method;
use Psalm\Storage\Assertion\Has_String_Array_Access;
use Psalm\Storage\Assertion\In_Array;
use Psalm\Storage\Assertion\Is_Countable;
use Psalm\Storage\Assertion\Is_Equal_Isset;
use Psalm\Storage\Assertion\Is_Greater_Than;
use Psalm\Storage\Assertion\Is_Isset;
use Psalm\Storage\Assertion\Is_Less_Than;
use Psalm\Storage\Assertion\Is_Loosely_Equal;
use Psalm\Storage\Assertion\Is_Type;
use Psalm\Storage\Assertion\Non_Empty;
use Psalm\Storage\Assertion\Non_Empty_Countable;
use Psalm\Storage\Assertion\Truthy;
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
use Psalm\Type\Atomic\T_Class_Constant;
use Psalm\Type\Atomic\T_Class_String;
use Psalm\Type\Atomic\T_False;
use Psalm\Type\Atomic\T_Float;
use Psalm\Type\Atomic\T_Generic_Object;
use Psalm\Type\Atomic\T_Int;
use Psalm\Type\Atomic\T_Int_Range;
use Psalm\Type\Atomic\T_Iterable;
use Psalm\Type\Atomic\T_Keyed_Array;
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
use Psalm\Type\Atomic\T_Non_Empty_Scalar;
use Psalm\Type\Atomic\T_Non_Empty_String;
use Psalm\Type\Atomic\T_Non_Falsy_String;
use Psalm\Type\Atomic\T_Nonspecific_Literal_String;
use Psalm\Type\Atomic\T_Numeric;
use Psalm\Type\Atomic\T_Numeric_String;
use Psalm\Type\Atomic\T_Object;
use Psalm\Type\Atomic\T_Object_With_Properties;
use Psalm\Type\Atomic\T_Resource;
use Psalm\Type\Atomic\T_Scalar;
use Psalm\Type\Atomic\T_String;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Atomic\T_True;
use Psalm\Type\Atomic\T_Value_Of;
use Psalm\Type\Reconciler;
use Psalm\Type\Union;
use function array_map;
use function array_merge;
use function array_values;
use function assert;
use function count;
use function explode;
use function in_array;
use function is_int;
use function min;
use function strlen;
use function strpos;
use function strtolower;
/**
 * This class receives a known type and an assertion (probably coming from AssertionFinder). The goal is to refine
 * the known type using the assertion. For example: old type is `int` assertion is `>5` result is `int<6, max>`.
 * Complex reconciliation takes part in AssertionReconciler if this class couldn't handle the reconciliation
 *
 * @internal
 */
final class Simple_Assertion_Reconciler extends Reconciler
{
    /**
     * @param   string[]  $suppressed_issues
     * @param Reconciler::RECONCILIATION_* $failed_reconciliation
     */
    public static function reconcile(Assertion $assertion, Codebase $codebase, Union $existing_var_type, ?string $key = null, bool $negated = false, ?Code_Location $code_location = null, array $suppressed_issues = [], int &$failed_reconciliation = Reconciler::RECONCILIATION_OK, bool $inside_loop = false): ?Union
    {
        if ($assertion instanceof Any) {
            return $existing_var_type;
        }
        $old_var_type_string = $existing_var_type->get_id();
        $is_equality = $assertion->has_equality();
        if ($assertion instanceof Is_Isset || $assertion instanceof Is_Equal_Isset) {
            return self::reconcile_isset($assertion, $existing_var_type, $key, $negated, $code_location, $suppressed_issues, $failed_reconciliation, $assertion instanceof Is_Equal_Isset, $inside_loop);
        }
        if ($assertion instanceof Array_Key_Exists) {
            return $existing_var_type->set_possibly_undefined(false);
        }
        if ($assertion instanceof In_Array) {
            return self::reconcile_in_array($assertion, $codebase, $existing_var_type, $key, $negated, $code_location, $suppressed_issues, $failed_reconciliation);
        }
        if ($assertion instanceof Has_Array_Key) {
            return self::reconcile_has_array_key($existing_var_type, $assertion);
        }
        if ($assertion instanceof Is_Greater_Than) {
            return self::reconcile_is_greater_than($assertion, $existing_var_type, $inside_loop, $old_var_type_string, $key, $negated, $code_location, $suppressed_issues);
        }
        if ($assertion instanceof Is_Less_Than) {
            return self::reconcile_is_less_than($assertion, $existing_var_type, $inside_loop, $old_var_type_string, $key, $negated, $code_location, $suppressed_issues);
        }
        if ($assertion instanceof Truthy || $assertion instanceof Non_Empty) {
            return self::reconcile_truthy_or_non_empty($assertion, $existing_var_type, $key, $negated, $code_location, $suppressed_issues, $failed_reconciliation, false);
        }
        if ($assertion instanceof Is_Countable) {
            return self::reconcile_countable($assertion, $codebase, $existing_var_type, $key, $negated, $code_location, $suppressed_issues, $failed_reconciliation, $is_equality);
        }
        if ($assertion instanceof Has_String_Array_Access) {
            return self::reconcile_string_array_access($assertion, $codebase, $existing_var_type, $key, $negated, $code_location, $suppressed_issues, $failed_reconciliation, $inside_loop);
        }
        if ($assertion instanceof Has_Int_Or_String_Array_Access) {
            return self::reconcile_int_array_access($assertion, $codebase, $existing_var_type, $key, $negated, $code_location, $suppressed_issues, $failed_reconciliation, $inside_loop);
        }
        if ($assertion instanceof Non_Empty_Countable) {
            return self::reconcile_non_empty_countable($assertion, $existing_var_type, $key, $negated, $code_location, $suppressed_issues, $is_equality);
        }
        if ($assertion instanceof Has_At_Least_Count) {
            return self::reconcile_non_empty_countable($assertion, $existing_var_type, $key, $negated, $code_location, $suppressed_issues, $is_equality);
        }
        if ($assertion instanceof Has_Exact_Count) {
            return self::reconcile_exactly_countable($existing_var_type, $assertion, $key, $negated, $code_location, $suppressed_issues, $is_equality);
        }
        if ($assertion instanceof Has_Method) {
            return self::reconcile_has_method($assertion, $codebase, $existing_var_type, $key, $negated, $code_location, $suppressed_issues, $failed_reconciliation);
        }
        $assertion_type = $assertion->get_atomic_type();
        if ($assertion_type instanceof T_Object) {
            return self::reconcile_object($codebase, $assertion, $assertion_type, $existing_var_type, $key, $negated, $code_location, $suppressed_issues, $failed_reconciliation, $is_equality);
        }
        if ($assertion_type instanceof T_Resource) {
            return self::reconcile_resource($assertion, $existing_var_type, $key, $negated, $code_location, $suppressed_issues, $failed_reconciliation, $is_equality);
        }
        if ($assertion_type instanceof T_Callable) {
            return self::reconcile_callable($assertion, $codebase, $existing_var_type, $key, $negated, $code_location, $suppressed_issues, $failed_reconciliation, $is_equality);
        }
        if ($assertion_type instanceof T_Iterable && $assertion_type->type_params[0]->is_mixed() && $assertion_type->type_params[1]->is_mixed()) {
            return self::reconcile_iterable($assertion, $codebase, $existing_var_type, $key, $negated, $code_location, $suppressed_issues, $failed_reconciliation, $is_equality);
        }
        if ($assertion_type instanceof T_Array && $assertion_type->type_params[0]->is_array_key() && $assertion_type->type_params[1]->is_mixed()) {
            return self::reconcile_array($assertion, $existing_var_type, $key, $negated, $code_location, $suppressed_issues, $failed_reconciliation, $is_equality);
        }
        if ($assertion_type instanceof T_Keyed_Array && $assertion_type->is_list && $assertion_type->get_generic_value_type()->is_mixed()) {
            return self::reconcile_list($assertion, $existing_var_type, $key, $negated, $code_location, $suppressed_issues, $failed_reconciliation, $is_equality, $assertion_type->is_non_empty());
        }
        if ($assertion_type instanceof T_Named_Object && $assertion_type->value === 'Traversable') {
            return self::reconcile_traversable($assertion, $codebase, $existing_var_type, $key, $negated, $code_location, $suppressed_issues, $failed_reconciliation, $is_equality);
        }
        if ($assertion_type instanceof T_Numeric) {
            return self::reconcile_numeric($assertion, $existing_var_type, $key, $negated, $code_location, $suppressed_issues, $failed_reconciliation, $is_equality);
        }
        if ($assertion_type instanceof T_Scalar) {
            return self::reconcile_scalar($assertion, $existing_var_type, $key, $negated, $code_location, $suppressed_issues, $failed_reconciliation, $is_equality);
        }
        if ($assertion_type && $assertion_type::class === T_Bool::class) {
            return self::reconcile_bool($assertion, $existing_var_type, $key, $negated, $code_location, $suppressed_issues, $failed_reconciliation, $is_equality);
        }
        if ($assertion_type && $assertion_type instanceof T_True) {
            return self::reconcile_true($assertion, $existing_var_type, $key, $negated, $code_location, $suppressed_issues, $failed_reconciliation, $is_equality);
        }
        if ($assertion_type && $assertion_type instanceof T_False) {
            return self::reconcile_false($assertion, $existing_var_type, $key, $negated, $code_location, $suppressed_issues, $failed_reconciliation, $is_equality);
        }
        if ($assertion_type && $assertion_type::class === T_String::class) {
            return self::reconcile_string($assertion, $existing_var_type, $key, $negated, $code_location, $suppressed_issues, $failed_reconciliation, $is_equality);
        }
        if ($assertion_type && $assertion_type::class === T_Int::class) {
            return self::reconcile_int($assertion, $existing_var_type, $key, $negated, $code_location, $suppressed_issues, $failed_reconciliation);
        }
        if ($assertion_type instanceof T_Float) {
            if ($existing_var_type->from_calculation && $existing_var_type->has_int()) {
                return Type::get_float();
            }
            if ($assertion instanceof Is_Loosely_Equal && $existing_var_type->is_string()) {
                return Type::get_numeric_string();
            }
        }
        if ($assertion_type instanceof T_Class_Constant) {
            return self::reconcile_class_constant($codebase, $assertion_type, $existing_var_type, $failed_reconciliation);
        }
        if ($existing_var_type->is_single() && $existing_var_type->has_template()) {
            $types = $existing_var_type->get_atomic_types();
            foreach ($types as $k => $atomic_type) {
                if ($atomic_type instanceof T_Template_Param && $assertion_type) {
                    if ($atomic_type->as->has_mixed() || $atomic_type->as->has_object()) {
                        unset($types[$k]);
                        $atomic_type = $atomic_type->replace_as(new Union([$assertion_type]));
                        $types[$atomic_type->get_key()] = $atomic_type;
                        return new Union($types);
                    }
                }
            }
        }
        if ($assertion_type instanceof T_Value_Of) {
            return self::reconcile_value_of($codebase, $assertion_type, $failed_reconciliation);
        }
        return null;
    }
    /**
     * @param   string[]  $suppressed_issues
     * @param Reconciler::RECONCILIATION_* $failed_reconciliation
     */
    private static function reconcile_isset(Assertion $assertion, Union $existing_var_type, ?string $key, bool $negated, ?Code_Location $code_location, array $suppressed_issues, int &$failed_reconciliation, bool $is_equality, bool $inside_loop): Union
    {
        $existing_var_type = $existing_var_type->get_builder();
        $old_var_type_string = $existing_var_type->get_id();
        // if key references an array offset
        $redundant = !($key && strpos($key, '[') || !$existing_var_type->initialized || $existing_var_type->possibly_undefined || $existing_var_type->ignore_isset);
        if ($existing_var_type->is_nullable()) {
            $existing_var_type->remove_type('null');
            $redundant = false;
        }
        if (!$existing_var_type->has_mixed() && !$is_equality && ($redundant || $existing_var_type->is_union_empty()) && $key && $code_location) {
            self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $key, $assertion, $redundant, $negated, $code_location, $suppressed_issues);
            if ($existing_var_type->is_union_empty()) {
                $failed_reconciliation = Reconciler::RECONCILIATION_EMPTY;
                return Type::get_never();
            }
        }
        if ($inside_loop) {
            if ($existing_var_type->has_type('never')) {
                $existing_var_type->remove_type('never');
                $existing_var_type->add_type(new T_Mixed(true));
            }
        }
        $existing_var_type->from_property = false;
        $existing_var_type->from_static_property = false;
        $existing_var_type->possibly_undefined = false;
        $existing_var_type->possibly_undefined_from_try = false;
        $existing_var_type->ignore_isset = false;
        return $existing_var_type->freeze();
    }
    /**
     * @param NonEmptyCountable|HasAtLeastCount $assertion
     * @param   string[]  $suppressed_issues
     */
    private static function reconcile_non_empty_countable(Assertion $assertion, Union $existing_var_type, ?string $key, bool $negated, ?Code_Location $code_location, array $suppressed_issues, bool $is_equality): Union
    {
        $old_var_type_string = $existing_var_type->get_id();
        $existing_var_type = $existing_var_type->get_builder();
        if ($existing_var_type->has_type('array')) {
            $array_atomic_type = $existing_var_type->get_array();
            $redundant = true;
            if ($array_atomic_type instanceof T_Array) {
                if (!$array_atomic_type instanceof T_Non_Empty_Array || $assertion instanceof Has_At_Least_Count && $array_atomic_type->min_count < $assertion->count) {
                    if ($array_atomic_type->is_empty_array()) {
                        $existing_var_type->remove_type('array');
                    } else {
                        $non_empty_array = new T_Non_Empty_Array($array_atomic_type->type_params, null, $assertion instanceof Has_At_Least_Count ? $assertion->count : null);
                        $existing_var_type->add_type($non_empty_array);
                    }
                    $redundant = false;
                }
            } elseif ($array_atomic_type instanceof T_Keyed_Array) {
                $prop_max_count = count($array_atomic_type->properties);
                $prop_min_count = $array_atomic_type->get_min_count();
                if ($assertion instanceof Has_At_Least_Count) {
                    // count($a) > 3
                    // count($a) >= 4
                    // 4
                    $count = $assertion->count;
                } else {
                    // count($a) >= 1
                    $count = 1;
                }
                if ($array_atomic_type->fallback_params === null) {
                    // We're asserting that count($a) >= $count
                    // If it's impossible, remove the type
                    // If it's possible but redundant, mark as redundant
                    // If it's possible, mark as not redundant
                    // Impossible because count($a) < $count always
                    if ($prop_max_count < $count) {
                        $redundant = false;
                        $existing_var_type->remove_type('array');
                        // Redundant because count($a) >= $count always
                    } elseif ($prop_min_count >= $count) {
                        $redundant = true;
                        // If count($a) === $count and there are possibly undefined properties
                    } elseif ($prop_max_count === $count && $prop_min_count !== $prop_max_count) {
                        $existing_var_type->remove_type('array');
                        $existing_var_type->add_type($array_atomic_type->set_properties(array_map(static fn(Union $union): \Psalm\Type\Union => $union->set_possibly_undefined(false), $array_atomic_type->properties)));
                        $redundant = false;
                        // Possible, alter type if we're a list
                    } elseif ($array_atomic_type->is_list) {
                        // Possible
                        $redundant = false;
                        $properties = $array_atomic_type->properties;
                        for ($i = $prop_min_count; $i < $count; $i++) {
                            $properties[$i] = $properties[$i]->set_possibly_undefined(false);
                        }
                        $array_atomic_type = $array_atomic_type->set_properties($properties);
                        $existing_var_type->remove_type('array');
                        $existing_var_type->add_type($array_atomic_type);
                    } else {
                        $redundant = false;
                    }
                } elseif ($array_atomic_type->is_list) {
                    if ($count <= $prop_min_count) {
                        $redundant = true;
                    } else {
                        $redundant = false;
                        $properties = $array_atomic_type->properties;
                        for ($i = $prop_min_count; $i < $count; $i++) {
                            $properties[$i] = isset($properties[$i]) ? $properties[$i]->set_possibly_undefined(false) : $array_atomic_type->fallback_params[1];
                        }
                        $array_atomic_type = $array_atomic_type->set_properties($properties);
                        $existing_var_type->remove_type('array');
                        $existing_var_type->add_type($array_atomic_type);
                    }
                } else {
                    $redundant = false;
                }
            }
            if (!$is_equality && !$existing_var_type->has_mixed() && ($redundant || $existing_var_type->is_union_empty())) {
                if ($key && $code_location) {
                    self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $key, $assertion, $redundant, $negated, $code_location, $suppressed_issues);
                }
            }
        }
        return $existing_var_type->freeze();
    }
    /**
     * @param array<string> $suppressed_issues
     */
    private static function reconcile_exactly_countable(Union $existing_var_type, Has_Exact_Count $assertion, ?string $key, bool $negated, ?Code_Location $code_location, array $suppressed_issues, bool $is_equality): Union
    {
        $existing_var_type = $existing_var_type->get_builder();
        if ($existing_var_type->has_type('array')) {
            $old_var_type_string = $existing_var_type->get_id();
            $array_atomic_type = $existing_var_type->get_array();
            $redundant = true;
            if ($array_atomic_type instanceof T_Array) {
                if (!$array_atomic_type instanceof T_Non_Empty_Array || $array_atomic_type->count !== $assertion->count) {
                    $non_empty_array = new T_Non_Empty_Array($array_atomic_type->type_params, $assertion->count);
                    $existing_var_type->remove_type('array');
                    $existing_var_type->add_type($non_empty_array);
                    $redundant = false;
                } else {
                    $redundant = true;
                }
            } elseif ($array_atomic_type instanceof T_Keyed_Array) {
                $prop_max_count = count($array_atomic_type->properties);
                $prop_min_count = $array_atomic_type->get_min_count();
                if ($assertion->count < $prop_min_count) {
                    // Impossible
                    $existing_var_type->remove_type('array');
                    $redundant = false;
                } elseif ($array_atomic_type->fallback_params === null) {
                    if ($assertion->count === $prop_min_count) {
                        // Redundant
                        $redundant = true;
                    } elseif ($assertion->count > $prop_max_count) {
                        // Impossible
                        $existing_var_type->remove_type('array');
                        $redundant = false;
                    } elseif ($assertion->count === $prop_max_count) {
                        $redundant = false;
                        $existing_var_type->remove_type('array');
                        $existing_var_type->add_type($array_atomic_type->set_properties(array_map(static fn(Union $union): \Psalm\Type\Union => $union->set_possibly_undefined(false), $array_atomic_type->properties)));
                    } elseif ($array_atomic_type->is_list) {
                        $redundant = false;
                        $properties = $array_atomic_type->properties;
                        for ($x = $prop_min_count; $x < $assertion->count; $x++) {
                            $properties[$x] = $properties[$x]->set_possibly_undefined(false);
                        }
                        $array_atomic_type = $array_atomic_type->set_properties($properties);
                        $existing_var_type->remove_type('array');
                        $existing_var_type->add_type($array_atomic_type);
                    } else {
                        $redundant = false;
                    }
                } else if ($array_atomic_type->is_list) {
                    $redundant = false;
                    $properties = $array_atomic_type->properties;
                    for ($x = $prop_min_count; $x < $assertion->count; $x++) {
                        $properties[$x] = isset($properties[$x]) ? $properties[$x]->set_possibly_undefined(false) : $array_atomic_type->fallback_params[1];
                    }
                    $array_atomic_type = new T_Keyed_Array($properties, null, null, true);
                    $existing_var_type->remove_type('array');
                    $existing_var_type->add_type($array_atomic_type);
                } elseif ($prop_max_count === $prop_min_count && $prop_max_count === $assertion->count) {
                    $existing_var_type->remove_type('array');
                    $existing_var_type->add_type($array_atomic_type->make_sealed());
                }
            }
            if (!$is_equality && !$existing_var_type->has_mixed() && ($redundant || $existing_var_type->is_union_empty())) {
                if ($key && $code_location) {
                    self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $key, $assertion, $redundant, $negated, $code_location, $suppressed_issues);
                }
            }
        }
        return $existing_var_type->freeze();
    }
    /**
     * @param   string[]  $suppressed_issues
     * @param Reconciler::RECONCILIATION_* $failed_reconciliation
     */
    private static function reconcile_has_method(Has_Method $assertion, Codebase $codebase, Union $existing_var_type, ?string $key, bool $negated, ?Code_Location $code_location, array $suppressed_issues, int &$failed_reconciliation): Union
    {
        $method_name = $assertion->method;
        $old_var_type_string = $existing_var_type->get_id();
        $existing_var_atomic_types = $existing_var_type->get_atomic_types();
        $object_types = [];
        $redundant = true;
        foreach ($existing_var_atomic_types as $type) {
            if ($type instanceof T_Named_Object && $codebase->class_or_interface_exists($type->value)) {
                if (!$codebase->method_exists($type->value . '::' . $method_name)) {
                    $match_found = false;
                    $extra_types = $type->extra_types;
                    foreach ($type->extra_types as $k => $extra_type) {
                        if ($extra_type instanceof T_Named_Object && $codebase->class_or_interface_exists($extra_type->value) && $codebase->method_exists($extra_type->value . '::' . $method_name)) {
                            $match_found = true;
                        } elseif ($extra_type instanceof T_Object_With_Properties) {
                            $match_found = true;
                            if (!isset($extra_type->methods[strtolower($method_name)])) {
                                unset($extra_types[$k]);
                                $extra_type = $extra_type->set_methods(array_merge($extra_type->methods, [strtolower($method_name) => 'object::' . $method_name]));
                                $extra_types[$extra_type->get_key()] = $extra_type;
                                $redundant = false;
                            }
                        }
                    }
                    if (!$match_found) {
                        $extra_type = new T_Object_With_Properties([], [strtolower($method_name) => $type->value . '::' . $method_name]);
                        $extra_types[$extra_type->get_key()] = $extra_type;
                        $redundant = false;
                    }
                    $type = $type->set_intersection_types($extra_types);
                }
                $object_types[] = $type;
            } elseif ($type instanceof T_Object_With_Properties) {
                if (!isset($type->methods[strtolower($method_name)])) {
                    $type = $type->set_methods(array_merge($type->methods, [strtolower($method_name) => 'object::' . $method_name]));
                    $redundant = false;
                }
                $object_types[] = $type;
            } elseif ($type instanceof T_Object || $type instanceof T_Mixed) {
                $object_types[] = new T_Object_With_Properties([], [strtolower($method_name) => 'object::' . $method_name]);
                $redundant = false;
            } elseif ($type instanceof T_String) {
                // we don’t know
                $object_types[] = $type;
                $redundant = false;
            } elseif ($type instanceof T_Template_Param) {
                $object_types[] = $type;
                $redundant = false;
            } else {
                $redundant = false;
            }
        }
        if (!$object_types || $redundant) {
            if ($key && $code_location) {
                self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $key, $assertion, $redundant, $negated, $code_location, $suppressed_issues);
            }
        }
        if ($object_types) {
            return new Union($object_types);
        }
        $failed_reconciliation = Reconciler::RECONCILIATION_EMPTY;
        return Type::get_never();
    }
    /**
     * @param   string[]  $suppressed_issues
     * @param Reconciler::RECONCILIATION_* $failed_reconciliation
     */
    private static function reconcile_string(Assertion $assertion, Union $existing_var_type, ?string $key, bool $negated, ?Code_Location $code_location, array $suppressed_issues, int &$failed_reconciliation, bool $is_equality): Union
    {
        $old_var_type_string = $existing_var_type->get_id();
        $existing_var_atomic_types = $existing_var_type->get_atomic_types();
        if ($existing_var_type->has_mixed()) {
            if ($assertion instanceof Is_Loosely_Equal) {
                return $existing_var_type;
            }
            return Type::get_string();
        }
        $string_types = [];
        $redundant = true;
        foreach ($existing_var_atomic_types as $type) {
            if ($type instanceof T_String) {
                if ($type::class === T_String::class) {
                    $type = $type->set_from_docblock(false);
                }
                $string_types[] = $type;
            } elseif ($type instanceof T_Callable) {
                $string_types[] = new T_Callable_String();
                $redundant = false;
            } elseif ($type instanceof T_Numeric) {
                $string_types[] = new T_Numeric_String();
                $redundant = false;
            } elseif ($type instanceof T_Scalar || $type instanceof T_Array_Key) {
                $string_types[] = new T_String();
                $redundant = false;
            } elseif ($type instanceof T_Template_Param) {
                if ($type->as->has_string() || $type->as->has_mixed() || $type->as->has_scalar()) {
                    $type = $type->replace_as(self::reconcile_string($assertion, $type->as, null, false, null, $suppressed_issues, $failed_reconciliation, $is_equality));
                    $string_types[] = $type;
                }
                $redundant = false;
            } elseif ($type instanceof T_Int && $assertion instanceof Is_Loosely_Equal) {
                // don't change the type of an int for non-strict comparisons
                $string_types[] = $type;
                $redundant = false;
            } else {
                $redundant = false;
            }
        }
        if (($redundant || !$string_types) && !$is_equality) {
            if ($key && $code_location) {
                self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $key, $assertion, $redundant, $negated, $code_location, $suppressed_issues);
            }
        }
        if ($string_types) {
            return new Union($string_types);
        }
        $failed_reconciliation = Reconciler::RECONCILIATION_EMPTY;
        return Type::get_never();
    }
    /**
     * @param   string[]  $suppressed_issues
     * @param Reconciler::RECONCILIATION_* $failed_reconciliation
     */
    private static function reconcile_int(Assertion $assertion, Union $existing_var_type, ?string $key, bool $negated, ?Code_Location $code_location, array $suppressed_issues, int &$failed_reconciliation): Union
    {
        if ($existing_var_type->has_mixed()) {
            if ($assertion instanceof Is_Loosely_Equal) {
                return $existing_var_type;
            }
            return Type::get_int();
        }
        $old_var_type_string = $existing_var_type->get_id();
        $existing_var_atomic_types = $existing_var_type->get_atomic_types();
        $int_types = [];
        $redundant = true;
        foreach ($existing_var_atomic_types as $type) {
            if ($type instanceof T_Int) {
                if ($type::class === T_Int::class) {
                    $type = $type->set_from_docblock(false);
                }
                $int_types[] = $type;
                if ($existing_var_type->from_calculation) {
                    $redundant = false;
                }
            } elseif ($type instanceof T_Numeric) {
                $int_types[] = new T_Int();
                $redundant = false;
            } elseif ($type instanceof T_Scalar || $type instanceof T_Array_Key) {
                $int_types[] = new T_Int();
                $redundant = false;
            } elseif ($type instanceof T_Template_Param) {
                if ($type->as->has_int() || $type->as->has_mixed()) {
                    $type = $type->replace_as(self::reconcile_int($assertion, $type->as, null, false, null, $suppressed_issues, $failed_reconciliation));
                    $int_types[] = $type;
                }
                $redundant = false;
            } elseif ($type instanceof T_String && $assertion instanceof Is_Loosely_Equal) {
                $int_types[] = new T_Numeric_String();
                $redundant = false;
            } else {
                $redundant = false;
            }
        }
        if (($redundant || !$int_types) && $assertion instanceof Is_Type) {
            if ($key && $code_location) {
                self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $key, $assertion, $redundant, $negated, $code_location, $suppressed_issues);
            }
        }
        if ($int_types) {
            return new Union($int_types);
        }
        $failed_reconciliation = Reconciler::RECONCILIATION_EMPTY;
        return Type::get_never();
    }
    /**
     * @param   string[]  $suppressed_issues
     * @param Reconciler::RECONCILIATION_* $failed_reconciliation
     */
    private static function reconcile_bool(Assertion $assertion, Union $existing_var_type, ?string $key, bool $negated, ?Code_Location $code_location, array $suppressed_issues, int &$failed_reconciliation, bool $is_equality): Union
    {
        if ($existing_var_type->has_mixed()) {
            return Type::get_bool();
        }
        $bool_types = [];
        $redundant = true;
        $old_var_type_string = $existing_var_type->get_id();
        $existing_var_atomic_types = $existing_var_type->get_atomic_types();
        foreach ($existing_var_atomic_types as $type) {
            if ($type instanceof T_Bool) {
                $type = $type->set_from_docblock(false);
                $bool_types[] = $type;
            } elseif ($type instanceof T_Scalar) {
                $bool_types[] = new T_Bool();
                $redundant = false;
            } elseif ($type instanceof T_Template_Param) {
                if ($type->as->has_bool() || $type->as->has_mixed()) {
                    $type = $type->replace_as(self::reconcile_bool($assertion, $type->as, null, false, null, $suppressed_issues, $failed_reconciliation, $is_equality));
                    $bool_types[] = $type;
                }
                $redundant = false;
            } else {
                $redundant = false;
            }
        }
        if (($redundant || !$bool_types) && !$is_equality) {
            if ($key && $code_location) {
                self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $key, $assertion, $redundant, $negated, $code_location, $suppressed_issues);
            }
        }
        if ($bool_types) {
            return new Union($bool_types);
        }
        $failed_reconciliation = Reconciler::RECONCILIATION_EMPTY;
        return Type::get_never();
    }
    /**
     * @param string[] $suppressed_issues
     * @param Reconciler::RECONCILIATION_* $failed_reconciliation
     */
    private static function reconcile_false(Assertion $assertion, Union $existing_var_type, ?string $key, bool $negated, ?Code_Location $code_location, array $suppressed_issues, int &$failed_reconciliation, bool $is_equality): Union
    {
        if ($existing_var_type->has_mixed()) {
            return Type::get_false();
        }
        if ($existing_var_type->has_scalar()) {
            return Type::get_false();
        }
        $old_var_type_string = $existing_var_type->get_id();
        $existing_var_atomic_types = $existing_var_type->get_atomic_types();
        $false_types = [];
        $redundant = true;
        foreach ($existing_var_atomic_types as $type) {
            if ($type instanceof T_False) {
                $false_types[] = $type;
            } elseif ($type instanceof T_Bool) {
                $false_types[] = new T_False();
                $redundant = false;
            } elseif ($type instanceof T_Template_Param && $type->as->is_mixed()) {
                $type = $type->replace_as(Type::get_false());
                $false_types[] = $type;
                $redundant = false;
            } elseif ($type instanceof T_Template_Param) {
                if ($type->as->has_scalar() || $type->as->has_mixed() || $type->as->has_bool()) {
                    $type = $type->replace_as(self::reconcile_false($assertion, $type->as, null, false, null, $suppressed_issues, $failed_reconciliation, $is_equality));
                    $false_types[] = $type;
                }
                $redundant = false;
            } else {
                $redundant = false;
            }
        }
        if ((!$false_types || $redundant) && !$is_equality) {
            if ($key && $code_location) {
                self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $key, $assertion, $redundant, $negated, $code_location, $suppressed_issues);
            }
        }
        if ($false_types) {
            return new Union($false_types);
        }
        $failed_reconciliation = Reconciler::RECONCILIATION_EMPTY;
        return Type::get_never();
    }
    /**
     * @param string[] $suppressed_issues
     * @param Reconciler::RECONCILIATION_* $failed_reconciliation
     */
    private static function reconcile_true(Assertion $assertion, Union $existing_var_type, ?string $key, bool $negated, ?Code_Location $code_location, array $suppressed_issues, int &$failed_reconciliation, bool $is_equality): Union
    {
        if ($existing_var_type->has_mixed()) {
            return Type::get_true();
        }
        if ($existing_var_type->has_scalar()) {
            return Type::get_true();
        }
        $old_var_type_string = $existing_var_type->get_id();
        $existing_var_atomic_types = $existing_var_type->get_atomic_types();
        $true_types = [];
        $redundant = true;
        foreach ($existing_var_atomic_types as $type) {
            if ($type instanceof T_True) {
                $true_types[] = $type;
            } elseif ($type instanceof T_Bool) {
                $true_types[] = new T_True();
                $redundant = false;
            } elseif ($type instanceof T_Template_Param && $type->as->is_mixed()) {
                $type = $type->replace_as(Type::get_true());
                $true_types[] = $type;
                $redundant = false;
            } elseif ($type instanceof T_Template_Param) {
                if ($type->as->has_scalar() || $type->as->has_mixed() || $type->as->has_bool()) {
                    $type = $type->replace_as(self::reconcile_true($assertion, $type->as, null, false, null, $suppressed_issues, $failed_reconciliation, $is_equality));
                    $true_types[] = $type;
                }
                $redundant = false;
            } else {
                $redundant = false;
            }
        }
        if ((!$true_types || $redundant) && !$is_equality) {
            if ($key && $code_location) {
                self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $key, $assertion, $redundant, $negated, $code_location, $suppressed_issues);
            }
        }
        if ($true_types) {
            return new Union($true_types);
        }
        $failed_reconciliation = Reconciler::RECONCILIATION_EMPTY;
        return Type::get_never();
    }
    /**
     * @param   string[]  $suppressed_issues
     * @param Reconciler::RECONCILIATION_* $failed_reconciliation
     */
    private static function reconcile_scalar(Assertion $assertion, Union $existing_var_type, ?string $key, bool $negated, ?Code_Location $code_location, array $suppressed_issues, int &$failed_reconciliation, bool $is_equality): Union
    {
        if ($existing_var_type->has_mixed()) {
            return Type::get_scalar();
        }
        $scalar_types = [];
        $redundant = true;
        $old_var_type_string = $existing_var_type->get_id();
        $existing_var_atomic_types = $existing_var_type->get_atomic_types();
        foreach ($existing_var_atomic_types as $type) {
            if ($type instanceof Scalar) {
                $scalar_types[] = $type;
            } elseif ($type instanceof T_Template_Param) {
                if ($type->as->has_scalar_type() || $type->as->has_mixed()) {
                    $type = $type->replace_as(self::reconcile_scalar($assertion, $type->as, null, false, null, $suppressed_issues, $failed_reconciliation, $is_equality));
                    $scalar_types[] = $type;
                }
                $redundant = false;
            } else {
                $redundant = false;
            }
        }
        if (($redundant || !$scalar_types) && !$is_equality) {
            if ($key && $code_location) {
                self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $key, $assertion, $redundant, $negated, $code_location, $suppressed_issues);
            }
        }
        if ($scalar_types) {
            return new Union($scalar_types);
        }
        $failed_reconciliation = Reconciler::RECONCILIATION_EMPTY;
        return Type::get_never();
    }
    /**
     * @param   string[]  $suppressed_issues
     * @param Reconciler::RECONCILIATION_* $failed_reconciliation
     */
    private static function reconcile_numeric(Assertion $assertion, Union $existing_var_type, ?string $key, bool $negated, ?Code_Location $code_location, array $suppressed_issues, int &$failed_reconciliation, bool $is_equality): Union
    {
        if ($existing_var_type->has_mixed()) {
            return Type::get_numeric();
        }
        $existing_var_type = $existing_var_type->get_builder();
        $old_var_type_string = $existing_var_type->get_id();
        $numeric_types = [];
        $redundant = true;
        if ($existing_var_type->has_string()) {
            $redundant = false;
            $existing_var_type->remove_type('string');
            $existing_var_type->add_type(new T_Numeric_String());
        }
        foreach ($existing_var_type->get_atomic_types() as $type) {
            if ($type instanceof T_Numeric || $type instanceof T_Numeric_String) {
                // this is a workaround for a possible issue running
                // is_numeric($a) && is_string($a)
                $redundant = false;
                $numeric_types[] = $type;
            } elseif ($type->is_numeric_type()) {
                $numeric_types[] = $type;
            } elseif ($type instanceof T_Scalar) {
                $redundant = false;
                $numeric_types[] = new T_Numeric();
            } elseif ($type instanceof T_Array_Key) {
                $redundant = false;
                $numeric_types[] = new T_Int();
                $numeric_types[] = new T_Numeric_String();
            } elseif ($type instanceof T_Template_Param) {
                if ($type->as->has_scalar_type() || $type->as->has_mixed()) {
                    $type = $type->replace_as(self::reconcile_numeric($assertion, $type->as, null, false, null, $suppressed_issues, $failed_reconciliation, $is_equality));
                    $numeric_types[] = $type;
                }
                $redundant = false;
            } else {
                $redundant = false;
            }
        }
        if (($redundant || !$numeric_types) && !$is_equality) {
            if ($key && $code_location) {
                self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $key, $assertion, $redundant, $negated, $code_location, $suppressed_issues);
            }
        }
        if ($numeric_types) {
            return new Union($numeric_types);
        }
        $failed_reconciliation = Reconciler::RECONCILIATION_EMPTY;
        return Type::get_never();
    }
    /**
     * @param   string[]  $suppressed_issues
     * @param Reconciler::RECONCILIATION_* $failed_reconciliation
     */
    private static function reconcile_object(Codebase $codebase, Assertion $assertion, T_Object $assertion_type, Union $existing_var_type, ?string $key, bool $negated, ?Code_Location $code_location, array $suppressed_issues, int &$failed_reconciliation, bool $is_equality): Union
    {
        if ($existing_var_type->has_mixed()) {
            return new Union([$assertion_type]);
        }
        $old_var_type_string = $existing_var_type->get_id();
        $existing_var_atomic_types = $existing_var_type->get_atomic_types();
        $object_types = [];
        $redundant = true;
        $assertion_type_is_intersectable_type = Type::is_intersection_type($assertion_type);
        foreach ($existing_var_atomic_types as $type) {
            if ($assertion_type_is_intersectable_type && self::are_intersection_types_allowed($codebase, $type)) {
                /** @var TNamedObject|TTemplateParam|TIterable|TObjectWithProperties|TCallableObject $assertion_type */
                $object_types[] = $type->add_intersection_type($assertion_type);
                $redundant = false;
            } elseif ($type instanceof T_Callable) {
                $callable_object = new T_Callable_Object($type->from_docblock, $type);
                $object_types[] = $callable_object;
                $redundant = false;
            } elseif ($type instanceof T_Template_Param && $type->as->is_mixed()) {
                $type = $type->replace_as(Type::get_object());
                $object_types[] = $type;
                $redundant = false;
            } elseif ($type instanceof T_Template_Param) {
                if ($type->as->has_object_type() || $type->as->has_mixed()) {
                    /**
                     * @psalm-suppress PossiblyInvalidArgument This looks wrong, psalm assumes that $assertion_type
                     *                                         can contain TNamedObject due to the reconciliation above
                     *                                         regarding {@see Type::isIntersectionType}. Due to the
                     *                                         native argument type `TObject`, the variable object will
                     *                                         never be `TNamedObject`.
                     */
                    $reconciled_type = self::reconcile_object($codebase, $assertion, $assertion_type, $type->as, null, false, null, $suppressed_issues, $failed_reconciliation, $is_equality);
                    $type = $type->replace_as($reconciled_type);
                    $object_types[] = $type;
                }
                $redundant = false;
            } elseif ($type->is_object_type()) {
                if ($assertion_type_is_intersectable_type && !self::are_intersection_types_allowed($codebase, $type)) {
                    $redundant = false;
                } else {
                    $object_types[] = $type;
                }
            } elseif ($type instanceof T_Iterable) {
                $params = $type->type_params;
                $params[0] = self::refine_array_key($params[0]);
                $object_types[] = new T_Generic_Object('Traversable', $params);
                $redundant = false;
            } else {
                $redundant = false;
            }
        }
        if ((!$object_types || $redundant) && !$is_equality) {
            if ($key && $code_location) {
                self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $key, $assertion, $redundant, $negated, $code_location, $suppressed_issues);
            }
        }
        if ($object_types) {
            return new Union($object_types);
        }
        $failed_reconciliation = Reconciler::RECONCILIATION_EMPTY;
        return Type::get_never();
    }
    /**
     * @param   string[]  $suppressed_issues
     * @param Reconciler::RECONCILIATION_* $failed_reconciliation
     */
    private static function reconcile_resource(Assertion $assertion, Union $existing_var_type, ?string $key, bool $negated, ?Code_Location $code_location, array $suppressed_issues, int &$failed_reconciliation, bool $is_equality): Union
    {
        if ($existing_var_type->has_mixed()) {
            return Type::get_resource();
        }
        $old_var_type_string = $existing_var_type->get_id();
        $existing_var_atomic_types = $existing_var_type->get_atomic_types();
        $resource_types = [];
        $redundant = true;
        foreach ($existing_var_atomic_types as $type) {
            if ($type instanceof T_Resource) {
                $resource_types[] = $type;
            } else {
                $redundant = false;
            }
        }
        if ((!$resource_types || $redundant) && !$is_equality) {
            if ($key && $code_location) {
                self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $key, $assertion, $redundant, $negated, $code_location, $suppressed_issues);
            }
        }
        if ($resource_types) {
            return new Union($resource_types);
        }
        $failed_reconciliation = Reconciler::RECONCILIATION_EMPTY;
        return Type::get_never();
    }
    /**
     * @param   string[]  $suppressed_issues
     * @param Reconciler::RECONCILIATION_* $failed_reconciliation
     */
    private static function reconcile_countable(Assertion $assertion, Codebase $codebase, Union $existing_var_type, ?string $key, bool $negated, ?Code_Location $code_location, array $suppressed_issues, int &$failed_reconciliation, bool $is_equality): Union
    {
        $old_var_type_string = $existing_var_type->get_id();
        $existing_var_atomic_types = $existing_var_type->get_atomic_types();
        if ($existing_var_type->has_mixed() || $existing_var_type->has_template()) {
            return new Union([Type::get_array_atomic(), new T_Named_Object('Countable')]);
        }
        $iterable_types = [];
        $redundant = true;
        foreach ($existing_var_atomic_types as $type) {
            if ($type->is_countable($codebase)) {
                $iterable_types[] = $type;
            } elseif ($type instanceof T_Object) {
                $iterable_types[] = new T_Named_Object('Countable');
                $redundant = false;
            } elseif ($type instanceof T_Named_Object || $type instanceof T_Iterable) {
                $countable = new T_Named_Object('Countable');
                $type = $type->add_intersection_type($countable);
                $iterable_types[] = $type;
                $redundant = false;
            } else {
                $redundant = false;
            }
        }
        if ((!$iterable_types || $redundant) && !$is_equality) {
            if ($key && $code_location) {
                self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $key, $assertion, $redundant, $negated, $code_location, $suppressed_issues);
            }
        }
        if ($iterable_types) {
            return new Union($iterable_types);
        }
        $failed_reconciliation = Reconciler::RECONCILIATION_EMPTY;
        return Type::get_never();
    }
    /**
     * @param   string[]  $suppressed_issues
     * @param Reconciler::RECONCILIATION_* $failed_reconciliation
     */
    private static function reconcile_iterable(Assertion $assertion, Codebase $codebase, Union $existing_var_type, ?string $key, bool $negated, ?Code_Location $code_location, array $suppressed_issues, int &$failed_reconciliation, bool $is_equality): Union
    {
        $old_var_type_string = $existing_var_type->get_id();
        $existing_var_atomic_types = $existing_var_type->get_atomic_types();
        if ($existing_var_type->has_mixed() || $existing_var_type->has_template()) {
            return new Union([new T_Iterable()]);
        }
        $iterable_types = [];
        $redundant = true;
        foreach ($existing_var_atomic_types as $type) {
            if ($type->is_iterable($codebase)) {
                $iterable_types[] = $type;
            } elseif ($type instanceof T_Object) {
                $iterable_types[] = new T_Named_Object('Traversable');
                $redundant = false;
            } else {
                $redundant = false;
            }
        }
        if ((!$iterable_types || $redundant) && !$is_equality) {
            if ($key && $code_location) {
                self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $key, $assertion, $redundant, $negated, $code_location, $suppressed_issues);
            }
        }
        if ($iterable_types) {
            return new Union($iterable_types);
        }
        $failed_reconciliation = Reconciler::RECONCILIATION_EMPTY;
        return Type::get_never();
    }
    /**
     * @param   string[]  $suppressed_issues
     * @param Reconciler::RECONCILIATION_* $failed_reconciliation
     */
    private static function reconcile_in_array(In_Array $assertion, Codebase $codebase, Union $existing_var_type, ?string $key, bool $negated, ?Code_Location $code_location, array $suppressed_issues, int &$failed_reconciliation): Union
    {
        $new_var_type = $assertion->type;
        if ($new_var_type->is_single() && $new_var_type->get_single_atomic() instanceof T_Class_Constant) {
            // Can't do assertion on const with non-literal type
            return $existing_var_type;
        }
        $intersection = Type::intersect_union_types($new_var_type, $existing_var_type, $codebase);
        if ($intersection === null) {
            if ($key && $code_location) {
                self::trigger_issue_for_impossible($existing_var_type, $existing_var_type->get_id(), $key, $assertion, true, $negated, $code_location, $suppressed_issues);
            }
            $failed_reconciliation = Reconciler::RECONCILIATION_EMPTY;
            return Type::get_never();
        }
        return $intersection;
    }
    private static function reconcile_has_array_key(Union $existing_var_type, Has_Array_Key $assertion): Union
    {
        $assertion = $assertion->key;
        $types = $existing_var_type->get_atomic_types();
        foreach ($types as &$atomic_type) {
            if ($atomic_type instanceof T_Keyed_Array) {
                assert(strpos($assertion, '::class') === strlen($assertion) - 7);
                [$assertion] = explode('::', $assertion);
                $atomic_type = new T_Keyed_Array(array_merge($atomic_type->properties, [$assertion => Type::get_mixed()]), array_merge($atomic_type->class_strings ?? [], [$assertion => true]), $atomic_type->fallback_params, $atomic_type->is_list);
            }
        }
        unset($atomic_type);
        return $existing_var_type->set_types($types);
    }
    /**
     * @param string[] $suppressed_issues
     */
    private static function reconcile_is_greater_than(Is_Greater_Than $assertion, Union $existing_var_type, bool $inside_loop, string $old_var_type_string, ?string $var_id, bool $negated, ?Code_Location $code_location, array $suppressed_issues): Union
    {
        $existing_var_type = $existing_var_type->get_builder();
        //we add 1 from the assertion value because we're on a strict operator
        $assertion_value = $assertion->value + 1;
        $redundant = true;
        if ($assertion->does_filter_null_or_false() && ($existing_var_type->has_type('null') || $existing_var_type->has_type('false'))) {
            $redundant = false;
            $existing_var_type->remove_type('null');
            $existing_var_type->remove_type('false');
        }
        foreach ($existing_var_type->get_atomic_types() as $atomic_type) {
            if ($atomic_type instanceof T_Int_Range) {
                if ($atomic_type->contains($assertion_value)) {
                    // if the range contains the assertion, the range must be adapted
                    $redundant = false;
                    $existing_var_type->remove_type($atomic_type->get_key());
                    $min_bound = $atomic_type->min_bound;
                    if ($min_bound === null) {
                        $min_bound = $assertion_value;
                    } else {
                        $min_bound = T_Int_Range::get_new_highest_bound($assertion_value, $min_bound);
                    }
                    $existing_var_type->add_type(new T_Int_Range($min_bound, $atomic_type->max_bound));
                } elseif ($atomic_type->is_lesser_than($assertion_value)) {
                    // if the range is lesser than the assertion, the type must be removed
                    $redundant = false;
                    $existing_var_type->remove_type($atomic_type->get_key());
                } elseif ($atomic_type->is_greater_than($assertion_value)) {
                    // if the range is greater than the assertion, the check is redundant
                }
            } elseif ($atomic_type instanceof T_Literal_Int) {
                if ($atomic_type->value < $assertion_value) {
                    $redundant = false;
                    $existing_var_type->remove_type($atomic_type->get_key());
                }
                /*elseif ($inside_loop) {
                      //when inside a loop, allow the range to extends the type
                      $existing_var_type->removeType($atomic_type->getKey());
                      if ($atomic_type->value < $assertion_value) {
                          $existing_var_type->addType(new TIntRange($atomic_type->value, $assertion_value));
                      } else {
                          $existing_var_type->addType(new TIntRange($assertion_value, $atomic_type->value));
                      }
                  }*/
            } elseif ($atomic_type instanceof T_Int && is_int($assertion_value)) {
                $redundant = false;
                $existing_var_type->remove_type($atomic_type->get_key());
                $existing_var_type->add_type(new T_Int_Range($assertion_value, null));
            } else {
                // we assume that other types may have been removed (empty strings? numeric strings?)
                //It may be worth refining to improve reconciliation while keeping in mind we're on loose comparison
                $redundant = false;
            }
        }
        if (!$inside_loop && $redundant && $var_id && $code_location) {
            self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $var_id, $assertion, true, $negated, $code_location, $suppressed_issues);
        }
        if ($existing_var_type->is_union_empty()) {
            if ($var_id && $code_location) {
                self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $var_id, $assertion, false, $negated, $code_location, $suppressed_issues);
            }
            $existing_var_type->add_type(new T_Never());
        }
        return $existing_var_type->freeze();
    }
    /**
     * @param string[] $suppressed_issues
     */
    private static function reconcile_is_less_than(Is_Less_Than $assertion, Union $existing_var_type, bool $inside_loop, string $old_var_type_string, ?string $var_id, bool $negated, ?Code_Location $code_location, array $suppressed_issues): Union
    {
        //we remove 1 from the assertion value because we're on a strict operator
        $assertion_value = $assertion->value - 1;
        $existing_var_type = $existing_var_type->get_builder();
        $redundant = true;
        if ($assertion->does_filter_null_or_false() && ($existing_var_type->has_type('null') || $existing_var_type->has_type('false'))) {
            $redundant = false;
            $existing_var_type->remove_type('null');
            $existing_var_type->remove_type('false');
        }
        foreach ($existing_var_type->get_atomic_types() as $atomic_type) {
            if ($atomic_type instanceof T_Int_Range) {
                if ($atomic_type->contains($assertion_value)) {
                    // if the range contains the assertion, the range must be adapted
                    $redundant = false;
                    $existing_var_type->remove_type($atomic_type->get_key());
                    $max_bound = $atomic_type->max_bound;
                    if ($max_bound === null) {
                        $max_bound = $assertion_value;
                    } else {
                        $max_bound = min($max_bound, $assertion_value);
                    }
                    $existing_var_type->add_type(new T_Int_Range($atomic_type->min_bound, $max_bound));
                } elseif ($atomic_type->is_lesser_than($assertion_value)) {
                    // if the range is lesser than the assertion, the check is redundant
                } elseif ($atomic_type->is_greater_than($assertion_value)) {
                    // if the range is greater than the assertion, the type must be removed
                    $redundant = false;
                    $existing_var_type->remove_type($atomic_type->get_key());
                }
            } elseif ($atomic_type instanceof T_Literal_Int) {
                if ($atomic_type->value > $assertion_value) {
                    $redundant = false;
                    $existing_var_type->remove_type($atomic_type->get_key());
                }
                /* elseif ($inside_loop) {
                       //when inside a loop, allow the range to extends the type
                       $existing_var_type->removeType($atomic_type->getKey());
                       if ($atomic_type->value < $assertion_value) {
                           $existing_var_type->addType(new TIntRange($atomic_type->value, $assertion_value));
                       } else {
                           $existing_var_type->addType(new TIntRange($assertion_value, $atomic_type->value));
                       }
                   }*/
            } elseif ($atomic_type instanceof T_Int) {
                $redundant = false;
                $existing_var_type->remove_type($atomic_type->get_key());
                $existing_var_type->add_type(new T_Int_Range(null, $assertion_value));
            } else {
                // we assume that other types may have been removed (empty strings? numeric strings?)
                //It may be worth refining to improve reconciliation while keeping in mind we're on loose comparison
                $redundant = false;
            }
        }
        if (!$inside_loop && $redundant && $var_id && $code_location) {
            self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $var_id, $assertion, true, $negated, $code_location, $suppressed_issues);
        }
        if ($existing_var_type->is_union_empty()) {
            if ($var_id && $code_location) {
                self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $var_id, $assertion, false, $negated, $code_location, $suppressed_issues);
            }
            $existing_var_type->add_type(new T_Never());
        }
        return $existing_var_type->freeze();
    }
    /**
     * @param   string[]  $suppressed_issues
     * @param Reconciler::RECONCILIATION_* $failed_reconciliation
     */
    private static function reconcile_traversable(Assertion $assertion, Codebase $codebase, Union $existing_var_type, ?string $key, bool $negated, ?Code_Location $code_location, array $suppressed_issues, int &$failed_reconciliation, bool $is_equality): Union
    {
        $old_var_type_string = $existing_var_type->get_id();
        $existing_var_atomic_types = $existing_var_type->get_atomic_types();
        if ($existing_var_type->has_mixed() || $existing_var_type->has_template()) {
            return new Union([new T_Named_Object('Traversable')]);
        }
        $traversable_types = [];
        $redundant = true;
        foreach ($existing_var_atomic_types as $type) {
            if ($type->has_traversable_interface($codebase)) {
                $traversable_types[] = $type;
            } elseif ($type instanceof T_Iterable) {
                $traversable_types[] = new T_Generic_Object('Traversable', $type->type_params);
                $redundant = false;
            } elseif ($type instanceof T_Object) {
                $traversable_types[] = new T_Named_Object('Traversable');
                $redundant = false;
            } elseif ($type instanceof T_Named_Object) {
                $traversable = new T_Named_Object('Traversable');
                $type = $type->add_intersection_type($traversable);
                $traversable_types[] = $type;
                $redundant = false;
            } else {
                $redundant = false;
            }
        }
        if ((!$traversable_types || $redundant) && !$is_equality) {
            if ($key && $code_location) {
                self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $key, $assertion, $redundant, $negated, $code_location, $suppressed_issues);
            }
        }
        if ($traversable_types) {
            return new Union($traversable_types);
        }
        $failed_reconciliation = Reconciler::RECONCILIATION_EMPTY;
        return Type::get_never();
    }
    /**
     * @param   string[]  $suppressed_issues
     * @param Reconciler::RECONCILIATION_* $failed_reconciliation
     */
    private static function reconcile_array(Assertion $assertion, Union $existing_var_type, ?string $key, bool $negated, ?Code_Location $code_location, array $suppressed_issues, int &$failed_reconciliation, bool $is_equality): Union
    {
        $old_var_type_string = $existing_var_type->get_id();
        $existing_var_atomic_types = $existing_var_type->get_atomic_types();
        if ($existing_var_type->has_mixed()) {
            if ($assertion->get_atomic_type()) {
                return new Union([$assertion->get_atomic_type()]);
            }
            return Type::get_array();
        }
        $atomic_assertion_type = $assertion->get_atomic_type();
        $array_types = [];
        $redundant = true;
        foreach ($existing_var_atomic_types as $type) {
            if ($type instanceof T_Array) {
                if ($atomic_assertion_type instanceof T_Non_Empty_Array) {
                    $array_types[] = new T_Non_Empty_Array($type->type_params, $atomic_assertion_type->count, $atomic_assertion_type->min_count, 'non-empty-array', $type->from_docblock);
                } else {
                    $array_types[] = $type;
                }
            } elseif ($type instanceof T_Keyed_Array) {
                //we don't currently have "definitely defined" shapes so we keep the one we have even if we have
                //a non-empty-array assertion
                $array_types[] = $type;
            } elseif ($type instanceof T_Callable) {
                $array_types[] = new T_Callable_Keyed_Array([new Union([new T_Class_String(), new T_Object()]), Type::get_non_empty_string()]);
                $redundant = false;
            } elseif ($type instanceof T_Iterable) {
                $params = $type->type_params;
                $params[0] = self::refine_array_key($params[0]);
                $array_types[] = new T_Array($params);
                $redundant = false;
            } elseif ($type instanceof T_Template_Param) {
                if ($type->as->has_array() || $type->as->has_iterable() || $type->as->has_mixed()) {
                    $type = $type->replace_as(self::reconcile_array($assertion, $type->as, null, false, null, $suppressed_issues, $failed_reconciliation, $is_equality));
                    $array_types[] = $type;
                }
                $redundant = false;
            } else {
                $redundant = false;
            }
        }
        if ((!$array_types || $redundant) && !$is_equality) {
            if ($key && $code_location) {
                self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $key, $assertion, $redundant, $negated, $code_location, $suppressed_issues);
                if ($redundant) {
                    $failed_reconciliation = Reconciler::RECONCILIATION_REDUNDANT;
                }
            }
        }
        if ($array_types) {
            return Type_Combiner::combine($array_types);
        }
        $failed_reconciliation = Reconciler::RECONCILIATION_EMPTY;
        return Type::get_never();
    }
    /**
     * @param   string[]  $suppressed_issues
     * @param Reconciler::RECONCILIATION_* $failed_reconciliation
     */
    private static function reconcile_list(Assertion $assertion, Union $existing_var_type, ?string $key, bool $negated, ?Code_Location $code_location, array $suppressed_issues, int &$failed_reconciliation, bool $is_equality, bool $is_non_empty): Union
    {
        $old_var_type_string = $existing_var_type->get_id();
        $existing_var_atomic_types = $existing_var_type->get_atomic_types();
        if ($existing_var_type->has_mixed() || $existing_var_type->has_template()) {
            return $is_non_empty ? Type::get_non_empty_list() : Type::get_list();
        }
        $array_types = [];
        $redundant = true;
        foreach ($existing_var_atomic_types as $type) {
            if ($type instanceof T_Keyed_Array && $type->is_list) {
                if ($is_non_empty && !$type->is_non_empty()) {
                    $properties = $type->properties;
                    $properties[0] = $properties[0]->set_possibly_undefined(false);
                    $array_types[] = $type->set_properties($properties);
                    $redundant = false;
                } else {
                    $array_types[] = $type;
                }
            } elseif ($type instanceof T_Array || $type instanceof T_Keyed_Array && $type->fallback_params !== null) {
                if ($type instanceof T_Keyed_Array) {
                    $type = $type->get_generic_array_type();
                }
                if ($type->type_params[0]->has_array_key() || $type->type_params[0]->has_int()) {
                    if ($type instanceof T_Non_Empty_Array || $is_non_empty) {
                        $array_types[] = Type::get_non_empty_list_atomic($type->type_params[1]);
                    } else {
                        $array_types[] = Type::get_list_atomic($type->type_params[1]);
                    }
                }
                if ($type->is_empty_array()) {
                    //we allow an empty array to pass as a list. We keep the type as empty array though (more precise)
                    $array_types[] = $type;
                }
                $redundant = false;
            } elseif ($type instanceof T_Callable) {
                $array_types[] = new T_Callable_Keyed_Array([new Union([new T_Class_String(), new T_Object()]), Type::get_non_empty_string()]);
                $redundant = false;
            } elseif ($type instanceof T_Iterable) {
                $array_types[] = $is_non_empty ? Type::get_non_empty_list_atomic($type->type_params[1]) : Type::get_list_atomic($type->type_params[1]);
                $redundant = false;
            } else {
                $redundant = false;
            }
        }
        if ((!$array_types || $redundant) && !$is_equality) {
            if ($key && $code_location) {
                self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $key, $assertion, $redundant, $negated, $code_location, $suppressed_issues);
                if ($redundant) {
                    $failed_reconciliation = Reconciler::RECONCILIATION_REDUNDANT;
                }
            }
        }
        if ($array_types) {
            return Type_Combiner::combine($array_types);
        }
        $failed_reconciliation = Reconciler::RECONCILIATION_EMPTY;
        return Type::get_never();
    }
    /**
     * @param   string[]  $suppressed_issues
     * @param Reconciler::RECONCILIATION_* $failed_reconciliation
     */
    private static function reconcile_string_array_access(Assertion $assertion, Codebase $codebase, Union $existing_var_type, ?string $key, bool $negated, ?Code_Location $code_location, array $suppressed_issues, int &$failed_reconciliation, bool $inside_loop): Union
    {
        $old_var_type_string = $existing_var_type->get_id();
        $existing_var_atomic_types = $existing_var_type->get_atomic_types();
        if ($existing_var_type->has_mixed() || $existing_var_type->has_template()) {
            return new Union([new T_Non_Empty_Array([Type::get_array_key(), Type::get_mixed()]), new T_Named_Object('ArrayAccess')]);
        }
        $array_types = [];
        foreach ($existing_var_atomic_types as $type) {
            if ($type->is_array_accessible_with_string_key($codebase)) {
                if ($type::class === T_Array::class) {
                    $array_types[] = new T_Non_Empty_Array($type->type_params);
                } elseif ($type instanceof T_Keyed_Array && $type->is_list) {
                    $properties = $type->properties;
                    $properties[0] = $properties[0]->set_possibly_undefined(false);
                    $array_types[] = $type->set_properties($properties);
                } else {
                    $array_types[] = $type;
                }
            } elseif ($type instanceof T_Template_Param) {
                $array_types[] = $type;
            }
        }
        if (!$array_types) {
            if ($key && $code_location) {
                self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $key, $assertion, true, $negated, $code_location, $suppressed_issues);
            }
        }
        if ($array_types) {
            return new Union($array_types);
        }
        $failed_reconciliation = Reconciler::RECONCILIATION_EMPTY;
        return Type::get_mixed($inside_loop);
    }
    /**
     * @param   string[]  $suppressed_issues
     * @param Reconciler::RECONCILIATION_* $failed_reconciliation
     */
    private static function reconcile_int_array_access(Assertion $assertion, Codebase $codebase, Union $existing_var_type, ?string $key, bool $negated, ?Code_Location $code_location, array $suppressed_issues, int &$failed_reconciliation, bool $inside_loop): Union
    {
        $old_var_type_string = $existing_var_type->get_id();
        $existing_var_atomic_types = $existing_var_type->get_atomic_types();
        if ($existing_var_type->has_mixed()) {
            return Type::get_mixed();
        }
        $array_types = [];
        foreach ($existing_var_atomic_types as $type) {
            if ($type->is_array_accessible_with_int_or_string_key($codebase)) {
                if ($type::class === T_Array::class) {
                    $array_types[] = new T_Non_Empty_Array($type->type_params);
                } else {
                    $array_types[] = $type;
                }
            } elseif ($type instanceof T_Template_Param) {
                $array_types[] = $type;
            }
        }
        if (!$array_types) {
            if ($key && $code_location) {
                self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $key, $assertion, true, $negated, $code_location, $suppressed_issues);
            }
        }
        if ($array_types) {
            return Type_Combiner::combine($array_types);
        }
        $failed_reconciliation = Reconciler::RECONCILIATION_EMPTY;
        return Type::get_mixed($inside_loop);
    }
    /**
     * @param   string[]  $suppressed_issues
     * @param Reconciler::RECONCILIATION_* $failed_reconciliation
     */
    private static function reconcile_callable(Assertion $assertion, Codebase $codebase, Union $existing_var_type, ?string $key, bool $negated, ?Code_Location $code_location, array $suppressed_issues, int &$failed_reconciliation, bool $is_equality): Union
    {
        if ($existing_var_type->has_mixed()) {
            return Type::parse_string('callable');
        }
        $old_var_type_string = $existing_var_type->get_id();
        $existing_var_atomic_types = $existing_var_type->get_atomic_types();
        $callable_types = [];
        $redundant = true;
        foreach ($existing_var_atomic_types as $type) {
            if ($type->is_callable_type()) {
                $callable_types[] = $type;
            } elseif ($type instanceof T_Object) {
                $callable_types[] = new T_Callable_Object();
                $redundant = false;
            } elseif ($type instanceof T_Named_Object && $codebase->class_exists($type->value) && $codebase->method_exists($type->value . '::__invoke')) {
                $callable_types[] = $type;
            } elseif ($type::class === T_String::class || $type::class === T_Non_Empty_String::class || $type::class === T_Non_Falsy_String::class) {
                $callable_types[] = new T_Callable_String();
                $redundant = false;
            } elseif ($type::class === T_Literal_String::class && Internal_Call_Map_Handler::in_call_map($type->value)) {
                $callable_types[] = $type;
                $redundant = false;
            } elseif ($type instanceof T_Array) {
                $type = new T_Callable_Keyed_Array($type->type_params);
                $callable_types[] = $type;
                $redundant = false;
            } elseif ($type instanceof T_Keyed_Array && count($type->properties) === 2) {
                $type = new T_Callable_Keyed_Array($type->properties);
                $callable_types[] = $type;
                $redundant = false;
            } elseif ($type instanceof T_Template_Param) {
                if ($type->as->has_callable_type() || $type->as->has_mixed()) {
                    $type = $type->replace_as(self::reconcile_callable($assertion, $codebase, $type->as, null, $negated, null, $suppressed_issues, $failed_reconciliation, $is_equality));
                }
                $redundant = false;
                $callable_types[] = $type;
            } elseif ($candidate_callable = Callable_Type_Comparator::get_callable_from_atomic($codebase, $type)) {
                $redundant = false;
                $callable_types[] = $candidate_callable;
            } else {
                $redundant = false;
            }
        }
        if ((!$callable_types || $redundant) && !$is_equality) {
            if ($key && $code_location) {
                self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $key, $assertion, $redundant, $negated, $code_location, $suppressed_issues);
            }
        }
        if ($callable_types) {
            return Type_Combiner::combine($callable_types);
        }
        $failed_reconciliation = Reconciler::RECONCILIATION_EMPTY;
        return Type::get_never();
    }
    /**
     * @param   Truthy|NonEmpty $assertion
     * @param   string[]  $suppressed_issues
     * @param Reconciler::RECONCILIATION_* $failed_reconciliation
     */
    private static function reconcile_truthy_or_non_empty(Assertion $assertion, Union $existing_var_type, ?string $key, bool $negated, ?Code_Location $code_location, array $suppressed_issues, int &$failed_reconciliation, bool $recursive_check): Union
    {
        $types = $existing_var_type->get_atomic_types();
        $old_var_type_string = $existing_var_type->get_id();
        //empty is used a lot to check for array offset existence, so we have to silent errors a lot
        $is_empty_assertion = $assertion instanceof Non_Empty;
        $redundant = !($existing_var_type->possibly_undefined || $existing_var_type->possibly_undefined_from_try);
        foreach ($types as $existing_var_type_key => $existing_var_type_part) {
            //if any atomic in the union is either always falsy, we remove it. If not always truthy, we mark the check
            //as not redundant.
            if ($existing_var_type_part->is_falsy()) {
                $redundant = false;
                unset($types[$existing_var_type_key]);
            } elseif ($existing_var_type->possibly_undefined || $existing_var_type->possibly_undefined_from_try || !$existing_var_type_part->is_truthy()) {
                $redundant = false;
            }
        }
        if (!$redundant && !$types) {
            //every type was removed, this is an impossible assertion
            if ($code_location && $key && !$is_empty_assertion && !$recursive_check) {
                self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $key, $assertion, false, $negated, $code_location, $suppressed_issues);
            }
            $failed_reconciliation = 2;
            return Type::get_never();
        }
        if ($redundant) {
            if ($code_location && $key && !$is_empty_assertion && !$recursive_check) {
                self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $key, $assertion, true, $negated, $code_location, $suppressed_issues);
            }
            $failed_reconciliation = 1;
            if (!$types) {
                throw new AssertionError("We must have some types here!");
            }
            return $existing_var_type->set_types($types);
        }
        if (isset($types['bool'])) {
            unset($types['bool']);
            $types[] = new T_True();
        }
        if (isset($types['array'])) {
            $array_atomic_type = $types['array'];
            if ($array_atomic_type instanceof T_Array && !$array_atomic_type instanceof T_Non_Empty_Array) {
                unset($types['array']);
                $types[] = new T_Non_Empty_Array($array_atomic_type->type_params);
            } elseif ($array_atomic_type instanceof T_Keyed_Array && $array_atomic_type->is_list && $array_atomic_type->properties[0]->possibly_undefined) {
                unset($types['array']);
                $properties = $array_atomic_type->properties;
                $properties[0] = $properties[0]->set_possibly_undefined(false);
                $types[] = $array_atomic_type->set_properties($properties);
            }
        }
        if (isset($types['mixed'])) {
            $mixed_atomic_type = $types['mixed'];
            if ($mixed_atomic_type::class === T_Mixed::class) {
                unset($types['mixed']);
                $types[] = new T_Non_Empty_Mixed();
            }
        }
        if (isset($types['scalar'])) {
            $scalar_atomic_type = $types['scalar'];
            if ($scalar_atomic_type::class === T_Scalar::class) {
                unset($types['scalar']);
                $types[] = new T_Non_Empty_Scalar();
            }
        }
        if (isset($types['string'])) {
            $string_atomic_type = $types['string'];
            if ($string_atomic_type::class === T_String::class) {
                unset($types['string']);
                $types[] = new T_Non_Falsy_String();
            } elseif ($string_atomic_type::class === T_Lowercase_String::class) {
                unset($types['string']);
                $types[] = new T_Non_Empty_Lowercase_String();
            } elseif ($string_atomic_type::class === T_Nonspecific_Literal_String::class) {
                unset($types['string']);
                $types[] = new T_Non_Empty_Nonspecific_Literal_String();
            } elseif ($string_atomic_type::class === T_Non_Empty_String::class) {
                unset($types['string']);
                $types[] = new T_Non_Falsy_String();
            }
        }
        if ($existing_var_type->has_int()) {
            $existing_range_types = $existing_var_type->get_range_ints();
            foreach ($existing_range_types as $int_key => $literal_type) {
                if ($literal_type->contains(0)) {
                    unset($types[$int_key]);
                    if ($literal_type->min_bound === null || $literal_type->min_bound <= -1) {
                        $types[] = new T_Int_Range($literal_type->min_bound, -1);
                    }
                    if ($literal_type->max_bound === null || $literal_type->max_bound >= 1) {
                        $types[] = new T_Int_Range(1, $literal_type->max_bound);
                    }
                }
            }
        }
        foreach ($types as $type_key => $existing_var_atomic_type) {
            if (!$existing_var_atomic_type instanceof T_Template_Param) {
                continue;
            }
            if ($existing_var_atomic_type->as->is_mixed()) {
                continue;
            }
            $template_did_fail = 0;
            $existing_var_atomic_type = $existing_var_atomic_type->replace_as(self::reconcile_truthy_or_non_empty($assertion, $existing_var_atomic_type->as, $key, $negated, $code_location, $suppressed_issues, $template_did_fail, true));
            if (!$template_did_fail) {
                unset($types[$type_key]);
                $types[] = $existing_var_atomic_type;
            }
        }
        if (!$types) {
            throw new AssertionError("We must have some types here!");
        }
        $new = $existing_var_type->set_types($types);
        if ($new === $existing_var_type && ($new->possibly_undefined || $new->possibly_undefined_from_try)) {
            $new = $existing_var_type->set_possibly_undefined(false, false);
        } else {
            /** @psalm-suppress InaccessibleProperty We just created this type */
            $new->possibly_undefined = false;
            /** @psalm-suppress InaccessibleProperty We just created this type */
            $new->possibly_undefined_from_try = false;
        }
        return $new;
    }
    /**
     * @param Reconciler::RECONCILIATION_* $failed_reconciliation
     */
    private static function reconcile_class_constant(Codebase $codebase, T_Class_Constant $class_constant_expression, Union $existing_type, int &$failed_reconciliation): Union
    {
        $class_name = $class_constant_expression->fq_classlike_name;
        if (!$codebase->classlike_storage_provider->has($class_name)) {
            return $existing_type;
        }
        $constant_pattern = $class_constant_expression->const_name;
        $resolver = new Class_Constant_By_Wildcard_Resolver($codebase);
        $matched_class_constant_types = $resolver->resolve($class_name, $constant_pattern);
        if ($matched_class_constant_types === null) {
            $failed_reconciliation = Reconciler::RECONCILIATION_EMPTY;
            return Type::get_never();
        }
        return Type_Combiner::combine(array_values($matched_class_constant_types), $codebase);
    }
    /**
     * @param Reconciler::RECONCILIATION_* $failed_reconciliation
     */
    private static function reconcile_value_of(Codebase $codebase, T_Value_Of $assertion_type, int &$failed_reconciliation): ?Union
    {
        $reconciled_types = [];
        // For now, only enums are supported here
        foreach ($assertion_type->type->get_atomic_types() as $atomic_type) {
            $enum_case_to_assert = null;
            if ($atomic_type instanceof T_Class_Constant) {
                $class_name = $atomic_type->fq_classlike_name;
                $enum_case_to_assert = $atomic_type->const_name;
            } elseif ($atomic_type instanceof T_Named_Object) {
                $class_name = $atomic_type->value;
            } else {
                return null;
            }
            if (!$codebase->class_or_interface_or_enum_exists($class_name)) {
                return null;
            }
            $class_storage = $codebase->classlike_storage_provider->get($class_name);
            if (!$class_storage->is_enum) {
                return null;
            }
            if (!in_array($class_storage->enum_type, ['string', 'int'], true)) {
                return null;
            }
            // For value-of<MyBackedEnum>, the assertion is meant to return *ANY* value of *ANY* enum case
            if ($enum_case_to_assert === null) {
                foreach ($class_storage->enum_cases as $enum_case) {
                    $enum_value = $enum_case->get_value($codebase->classlikes);
                    assert($enum_value !== null, 'Verified enum type above, value can not contain `null` anymore.');
                    $reconciled_types[] = $enum_value;
                }
                continue;
            }
            $enum_case = $class_storage->enum_cases[$enum_case_to_assert] ?? null;
            if ($enum_case === null) {
                return null;
            }
            $enum_value = $enum_case->get_value($codebase->classlikes);
            assert($enum_value !== null, 'Verified enum type above, value can not contain `null` anymore.');
            $reconciled_types[] = $enum_value;
        }
        if ($reconciled_types === []) {
            $failed_reconciliation = Reconciler::RECONCILIATION_EMPTY;
            return Type::get_never();
        }
        return Type_Combiner::combine($reconciled_types, $codebase, false, false);
    }
    /**
     * @psalm-assert-if-true TCallableObject|TObjectWithProperties|TNamedObject $type
     */
    private static function are_intersection_types_allowed(Codebase $codebase, Atomic $type): bool
    {
        if ($type instanceof T_Object_With_Properties || $type instanceof T_Callable_Object) {
            return true;
        }
        if (!$type instanceof T_Named_Object || !$codebase->classlike_storage_provider->has($type->value)) {
            return false;
        }
        $class_storage = $codebase->classlike_storage_provider->get($type->value);
        return !$class_storage->final;
    }
}