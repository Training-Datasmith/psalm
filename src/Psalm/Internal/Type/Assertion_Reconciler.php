<?php

declare (strict_types=1);
namespace Psalm\Internal\Type;

use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\Statements\Expression\Fetch\Variable_Fetch_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Analyzer\Trait_Analyzer;
use Psalm\Internal\Type\Comparator\Atomic_Type_Comparator;
use Psalm\Internal\Type\Comparator\Type_Comparison_Result;
use Psalm\Internal\Type\Comparator\Union_Type_Comparator;
use Psalm\Issue\Docblock_Type_Contradiction;
use Psalm\Issue\Type_Does_Not_Contain_Null;
use Psalm\Issue\Type_Does_Not_Contain_Type;
use Psalm\Issue_Buffer;
use Psalm\Storage\Assertion;
use Psalm\Storage\Assertion\Array_Key_Exists;
use Psalm\Storage\Assertion\Falsy;
use Psalm\Storage\Assertion\Has_At_Least_Count;
use Psalm\Storage\Assertion\Has_Exact_Count;
use Psalm\Storage\Assertion\Is_A_Class;
use Psalm\Storage\Assertion\Is_Class_Equal;
use Psalm\Storage\Assertion\Is_Equal_Isset;
use Psalm\Storage\Assertion\Is_Isset;
use Psalm\Storage\Assertion\Is_Loosely_Equal;
use Psalm\Storage\Assertion\Nested_Assertions;
use Psalm\Storage\Assertion\Non_Empty;
use Psalm\Storage\Assertion\Non_Empty_Countable;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\Scalar;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Array_Key;
use Psalm\Type\Atomic\T_Class_Constant;
use Psalm\Type\Atomic\T_Class_String;
use Psalm\Type\Atomic\T_Enum_Case;
use Psalm\Type\Atomic\T_Float;
use Psalm\Type\Atomic\T_Generic_Object;
use Psalm\Type\Atomic\T_Int;
use Psalm\Type\Atomic\T_Int_Range;
use Psalm\Type\Atomic\T_Iterable;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Atomic\T_Literal_Float;
use Psalm\Type\Atomic\T_Literal_Int;
use Psalm\Type\Atomic\T_Literal_String;
use Psalm\Type\Atomic\T_Lowercase_String;
use Psalm\Type\Atomic\T_Mixed;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Non_Empty_Lowercase_String;
use Psalm\Type\Atomic\T_Non_Empty_Nonspecific_Literal_String;
use Psalm\Type\Atomic\T_Non_Empty_String;
use Psalm\Type\Atomic\T_Null;
use Psalm\Type\Atomic\T_Numeric;
use Psalm\Type\Atomic\T_Object;
use Psalm\Type\Atomic\T_Scalar;
use Psalm\Type\Atomic\T_String;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Atomic\T_Template_Param_Class;
use Psalm\Type\Reconciler;
use Psalm\Type\Union;
use function array_intersect_key;
use function array_merge;
use function count;
use function is_string;
/**
 * @internal
 */
final class Assertion_Reconciler extends Reconciler
{
    /**
     * Reconciles types
     *
     * think of this as a set of functions e.g. empty(T), notEmpty(T), null(T), notNull(T) etc. where
     *  - empty(Object) => null,
     *  - empty(bool) => false,
     *  - notEmpty(Object|null) => Object,
     *  - notEmpty(Object|false) => Object
     *
     * @param   string[]            $suppressed_issues
     * @param   array<string, array<string, Union>> $template_type_map
     * @param-out Reconciler::RECONCILIATION_* $failed_reconciliation
     */
    public static function reconcile(Assertion $assertion, ?Union $existing_var_type, ?string $key, Statements_Analyzer $statements_analyzer, bool $inside_loop, array $template_type_map, ?Code_Location $code_location = null, array $suppressed_issues = [], ?int &$failed_reconciliation = Reconciler::RECONCILIATION_OK, bool $negated = false): Union
    {
        $codebase = $statements_analyzer->get_codebase();
        $failed_reconciliation = Reconciler::RECONCILIATION_OK;
        $is_negation = $assertion->is_negation();
        if ($assertion instanceof Nested_Assertions) {
            $assertion = new Falsy();
            $is_negation = true;
        }
        if ($existing_var_type === null && is_string($key) && Variable_Fetch_Analyzer::is_super_global($key)) {
            $existing_var_type = Variable_Fetch_Analyzer::get_global_type($key, $codebase->analysis_php_version_id);
        }
        if ($existing_var_type === null) {
            return self::get_missing_type($assertion, $inside_loop);
        }
        $old_var_type_string = $existing_var_type->get_id();
        if ($is_negation) {
            return Negated_Assertion_Reconciler::reconcile($statements_analyzer, $assertion, $existing_var_type, $old_var_type_string, $key, $negated, $code_location, $suppressed_issues, $failed_reconciliation, $inside_loop);
        }
        $assertion_type = $assertion->get_atomic_type();
        if ($assertion_type instanceof T_Literal_Int || $assertion_type instanceof T_Literal_String || $assertion_type instanceof T_Literal_Float || $assertion_type instanceof T_Enum_Case) {
            return self::handle_literal_equality($statements_analyzer, $assertion, $assertion_type, $existing_var_type, $old_var_type_string, $key, $negated, $code_location, $suppressed_issues);
        }
        if ($assertion instanceof Is_A_Class) {
            $should_return = false;
            $new_type_parts = self::handle_is_a($assertion, $codebase, $existing_var_type, $code_location, $key, $suppressed_issues, $should_return);
            if ($should_return) {
                return new Union($new_type_parts);
            }
            $new_type_part = $new_type_parts[0];
        } else {
            $simply_reconciled_type = Simple_Assertion_Reconciler::reconcile($assertion, $codebase, $existing_var_type, $key, $negated, $code_location, $suppressed_issues, $failed_reconciliation, $inside_loop);
            if ($simply_reconciled_type) {
                return $simply_reconciled_type;
            }
            if ($assertion instanceof Is_Class_Equal) {
                $new_type_part = Atomic::create($assertion->type, null, $template_type_map);
            } elseif ($assertion_type = $assertion->get_atomic_type()) {
                $new_type_part = $assertion_type;
            } else {
                $new_type_part = new T_Mixed();
            }
        }
        if ($existing_var_type->has_mixed()) {
            if ($assertion instanceof Is_Loosely_Equal && $new_type_part instanceof Scalar) {
                return $existing_var_type;
            }
            return new Union([$new_type_part]);
        }
        $refined_type = self::refine($statements_analyzer, $assertion, $new_type_part, $existing_var_type, $key, $negated, $code_location, $suppressed_issues, $failed_reconciliation);
        return Type_Expander::expand_union($codebase, $refined_type, null, null, null, true, false, false, true);
    }
    private static function get_missing_type(Assertion $assertion, bool $inside_loop): Union
    {
        if ($assertion instanceof Is_Isset || $assertion instanceof Is_Equal_Isset || $assertion instanceof Non_Empty) {
            return Type::get_mixed($inside_loop);
        }
        if ($assertion instanceof Array_Key_Exists || $assertion instanceof Non_Empty_Countable || $assertion instanceof Has_Exact_Count || $assertion instanceof Has_At_Least_Count) {
            return Type::get_mixed();
        }
        if (!$assertion->is_negation()) {
            $assertion_type = $assertion->get_atomic_type();
            if ($assertion_type) {
                return new Union([$assertion_type]);
            }
        }
        return Type::get_mixed();
    }
    /**
     * This method is called when SimpleAssertionReconciler was not enough. It receives the existing type, the assertion
     * and also a new type created from the assertion string.
     *
     * @param Reconciler::RECONCILIATION_* $failed_reconciliation
     * @param   string[]    $suppressed_issues
     * @param-out Reconciler::RECONCILIATION_* $failed_reconciliation
     */
    private static function refine(Statements_Analyzer $statements_analyzer, Assertion $assertion, Atomic $new_type_part, Union &$existing_var_type, ?string $key, bool $negated, ?Code_Location $code_location, array $suppressed_issues, int &$failed_reconciliation): Union
    {
        $codebase = $statements_analyzer->get_codebase();
        $old_var_type_string = $existing_var_type->get_id();
        if ($new_type_part instanceof T_Mixed) {
            return $existing_var_type;
        }
        $new_type_has_interface = false;
        if ($new_type_part->is_object_type()) {
            if ($new_type_part instanceof T_Named_Object && $codebase->interface_exists($new_type_part->value)) {
                $new_type_has_interface = true;
            }
        }
        $old_type_has_interface = false;
        if ($existing_var_type->has_object_type()) {
            foreach ($existing_var_type->get_atomic_types() as $existing_type_part) {
                if ($existing_type_part instanceof T_Named_Object && $codebase->interface_exists($existing_type_part->value)) {
                    $old_type_has_interface = true;
                    break;
                }
            }
        }
        if ($new_type_part instanceof T_Template_Param && $new_type_part->as->is_single()) {
            $new_as_atomic = $new_type_part->as->get_single_atomic();
            $acceptable_atomic_types = [];
            foreach ($existing_var_type->get_atomic_types() as $existing_var_type_part) {
                if ($existing_var_type_part instanceof T_Named_Object || $existing_var_type_part instanceof T_Template_Param) {
                    $acceptable_atomic_types[] = $existing_var_type_part;
                } else if (Atomic_Type_Comparator::is_contained_by($codebase, $existing_var_type_part, $new_as_atomic)) {
                    $acceptable_atomic_types[] = $existing_var_type_part;
                }
            }
            if ($acceptable_atomic_types) {
                $acceptable_atomic_types = count($acceptable_atomic_types) === count($existing_var_type->get_atomic_types()) ? $existing_var_type : new Union($acceptable_atomic_types);
                return new Union([$new_type_part->replace_as($acceptable_atomic_types)]);
            }
        }
        if ($new_type_part instanceof T_Keyed_Array) {
            $acceptable_atomic_types = [];
            foreach ($existing_var_type->get_atomic_types() as $existing_var_type_part) {
                if (!$existing_var_type_part instanceof T_Keyed_Array) {
                    continue;
                }
                if (array_intersect_key($existing_var_type_part->properties, $new_type_part->properties)) {
                    continue;
                }
                $acceptable_atomic_types[] = $existing_var_type_part->set_properties(array_merge($existing_var_type_part->properties, $new_type_part->properties));
            }
            if ($acceptable_atomic_types) {
                return new Union($acceptable_atomic_types);
            }
        }
        $new_type = null;
        if ($new_type_part instanceof T_Named_Object && ($new_type_has_interface || $old_type_has_interface) && !Union_Type_Comparator::can_expression_types_be_identical($codebase, new Union([$new_type_part]), $existing_var_type, false)) {
            $acceptable_atomic_types = [];
            foreach ($existing_var_type->get_atomic_types() as $existing_var_type_part) {
                if (Atomic_Type_Comparator::is_contained_by($codebase, $existing_var_type_part, $new_type_part)) {
                    $acceptable_atomic_types[] = $existing_var_type_part;
                    continue;
                }
                if ($existing_var_type_part instanceof T_Named_Object && ($codebase->class_exists($existing_var_type_part->value) || $codebase->interface_exists($existing_var_type_part->value))) {
                    $existing_var_type_part = $existing_var_type_part->add_intersection_type($new_type_part);
                    $acceptable_atomic_types[] = $existing_var_type_part;
                }
                if ($existing_var_type_part instanceof T_Template_Param) {
                    $existing_var_type_part = $existing_var_type_part->add_intersection_type($new_type_part);
                    $acceptable_atomic_types[] = $existing_var_type_part;
                }
            }
            if ($acceptable_atomic_types) {
                return new Union($acceptable_atomic_types);
            }
        } elseif (!$new_type_part instanceof T_Mixed) {
            $any_scalar_type_match_found = false;
            if ($code_location && $key && !$assertion->has_equality() && $new_type_part instanceof T_Named_Object && !$new_type_has_interface && (!$statements_analyzer->get_source()->get_source() instanceof Trait_Analyzer || $key !== '$this') && Union_Type_Comparator::is_contained_by($codebase, $existing_var_type, new Union([$new_type_part]), false, false, null, false, false)) {
                self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $key, $assertion, true, $negated, $code_location, $suppressed_issues);
            }
            $intersection_type = self::filter_type_with_another($codebase, $existing_var_type, new Union([$new_type_part]), $any_scalar_type_match_found);
            if ($code_location && !$intersection_type && (!$assertion instanceof Is_Loosely_Equal || !$any_scalar_type_match_found)) {
                if ($new_type_part instanceof T_Null) {
                    if ($existing_var_type->from_docblock) {
                        Issue_Buffer::maybe_add(new Docblock_Type_Contradiction('Cannot resolve types for ' . $key . ' - docblock-defined type ' . $existing_var_type . ' does not contain null', $code_location, $existing_var_type->get_id() . ' null'), $suppressed_issues);
                    } else {
                        Issue_Buffer::maybe_add(new Type_Does_Not_Contain_Null('Cannot resolve types for ' . $key . ' - ' . $existing_var_type . ' does not contain null', $code_location, $existing_var_type->get_id()), $suppressed_issues);
                    }
                } elseif (!$statements_analyzer->get_source()->get_source() instanceof Trait_Analyzer || $key !== '$this' && !($existing_var_type->has_literal_class_string() && $assertion instanceof Is_A_Class)) {
                    if ($existing_var_type->from_docblock) {
                        Issue_Buffer::maybe_add(new Docblock_Type_Contradiction('Cannot resolve types for ' . $key . ' - docblock-defined type ' . $existing_var_type->get_id() . ' does not contain ' . $new_type_part->get_id(), $code_location, $existing_var_type->get_id() . ' ' . $new_type_part->get_id()), $suppressed_issues);
                    } else {
                        Issue_Buffer::maybe_add(new Type_Does_Not_Contain_Type('Cannot resolve types for ' . $key . ' - ' . $existing_var_type->get_id() . ' does not contain ' . $new_type_part->get_id(), $code_location, $existing_var_type->get_id() . ' ' . $new_type_part->get_id()), $suppressed_issues);
                    }
                }
                $failed_reconciliation = Reconciler::RECONCILIATION_EMPTY;
            }
            if ($intersection_type) {
                $new_type = $intersection_type;
            }
        }
        return $new_type ?: new Union([$new_type_part]);
    }
    /**
     * This method receives two types. The goal is to use data in the new type to reduce the existing_type to a more
     * precise version. For example: new is `array<int>` old is `list<mixed>` so the result is `list<int>`
     */
    private static function filter_type_with_another(Codebase $codebase, Union &$existing_type, Union $new_type, bool &$any_scalar_type_match_found = false): ?Union
    {
        $matching_atomic_types = [];
        $existing_types = $existing_type->get_atomic_types();
        foreach ($new_type->get_atomic_types() as $new_type_part) {
            foreach ($existing_types as &$existing_type_part) {
                $matching_atomic_type = self::filter_atomic_with_another($existing_type_part, $new_type_part, $codebase, $any_scalar_type_match_found);
                if ($matching_atomic_type) {
                    $matching_atomic_types[] = $matching_atomic_type;
                }
            }
            unset($existing_type_part);
        }
        $existing_type = $existing_type->set_types($existing_types);
        if ($matching_atomic_types) {
            return new Union($matching_atomic_types);
        }
        return null;
    }
    private static function filter_atomic_with_another(Atomic &$type_1_atomic, Atomic $type_2_atomic, Codebase $codebase, bool &$any_scalar_type_match_found): ?Atomic
    {
        if ($type_1_atomic instanceof T_Float && $type_2_atomic instanceof T_Int) {
            $any_scalar_type_match_found = true;
            return $type_2_atomic;
        }
        if ($type_1_atomic instanceof T_Named_Object) {
            $type_1_atomic = $type_1_atomic->set_is_static(false);
        }
        $atomic_comparison_results = new Type_Comparison_Result();
        $atomic_contained_by = Atomic_Type_Comparator::is_contained_by($codebase, $type_2_atomic, $type_1_atomic, !($type_1_atomic instanceof T_Named_Object && $type_2_atomic instanceof T_Named_Object), false, $atomic_comparison_results);
        if ($atomic_contained_by) {
            return self::refine_contained_atomic_with_another($type_1_atomic, $type_2_atomic, $codebase, $atomic_comparison_results->type_coerced ?? false);
        }
        $atomic_comparison_results = new Type_Comparison_Result();
        $atomic_contained_by = Atomic_Type_Comparator::is_contained_by($codebase, $type_1_atomic, $type_2_atomic, $type_1_atomic instanceof T_Class_String && $type_2_atomic instanceof T_Class_String, false, $atomic_comparison_results);
        if ($atomic_contained_by) {
            return self::refine_contained_atomic_with_another($type_2_atomic, $type_1_atomic, $codebase, $atomic_comparison_results->type_coerced ?? false);
        }
        $matching_atomic_type = null;
        if ($type_1_atomic instanceof T_Named_Object && $type_2_atomic instanceof T_Named_Object && ($codebase->interface_exists($type_1_atomic->value) || $codebase->interface_exists($type_2_atomic->value))) {
            return $type_2_atomic->add_intersection_type($type_1_atomic);
        }
        /*if ($type_2_atomic instanceof TKeyedArray
                ) {
                    $type_2_key = $type_2_atomic->getGenericKeyType();
                    $type_2_value = $type_2_atomic->getGenericValueType();
        
                    if (!$type_2_key->hasString()) {
                        $type_1_type_param = $type_1_atomic->type_param;
                        $type_2_value = self::filterTypeWithAnother(
                            $codebase,
                            $type_1_type_param,
                            $type_2_value,
                            $any_scalar_type_match_found
                        );
                        $type_1_atomic = $type_1_atomic->setTypeParam($type_1_type_param);
        
                        if ($type_2_value === null) {
                            return null;
                        }
        
                        return new TKeyedArray(
                            $type_2_atomic->properties,
                            null,
                            [Type::getInt(), $type_2_value],
                            true
                        );
                    }
                } elseif ($type_1_atomic instanceof TKeyedArray
                    && $type_2_atomic instanceof \Psalm\Type\Atomic\TList
                ) {
                    $type_1_key = $type_1_atomic->getGenericKeyType();
                    $type_1_value = $type_1_atomic->getGenericValueType();
        
                    if (!$type_1_key->hasString()) {
                        $type_2_type_param = $type_2_atomic->type_param;
                        $type_1_value = self::filterTypeWithAnother(
                            $codebase,
                            $type_2_type_param,
                            $type_1_value,
                            $any_scalar_type_match_found
                        );
        
                        if ($type_1_value === null) {
                            return null;
                        }
        
                        return new TKeyedArray(
                            $type_1_atomic->properties,
                            null,
                            [Type::getInt(), $type_1_value],
                            true
                        );
                    }
                }*/
        if ($type_2_atomic instanceof T_Template_Param && $type_1_atomic instanceof T_Template_Param && $type_2_atomic->param_name !== $type_1_atomic->param_name && $type_2_atomic->as->has_object() && $type_1_atomic->as->has_object()) {
            return $type_2_atomic->add_intersection_type($type_1_atomic);
        }
        //we filter both types of standard iterables
        if (($type_2_atomic instanceof T_Generic_Object || $type_2_atomic instanceof T_Array || $type_2_atomic instanceof T_Iterable) && ($type_1_atomic instanceof T_Generic_Object || $type_1_atomic instanceof T_Array || $type_1_atomic instanceof T_Iterable) && count($type_2_atomic->type_params) === count($type_1_atomic->type_params)) {
            $type_1_params = $type_1_atomic->type_params;
            foreach ($type_2_atomic->type_params as $i => $type_2_param) {
                $type_1_param = $type_1_params[$i];
                $type_2_param_id = $type_2_param->get_id();
                $type_2_param = self::filter_type_with_another($codebase, $type_1_param, $type_2_param, $any_scalar_type_match_found);
                if ($type_2_param === null) {
                    return null;
                }
                if ($type_1_params[$i]->get_id() !== $type_2_param_id) {
                    $type_1_params[$i] = $type_2_param;
                }
            }
            /** @psalm-suppress InvalidArgument */
            $type_1_atomic = $type_1_atomic->set_type_params($type_1_params);
            $matching_atomic_type = $type_1_atomic;
            $atomic_comparison_results->type_coerced = true;
        }
        //we filter the second part of a list with the second part of standard iterables
        /*if (($type_2_atomic instanceof TArray
                        || $type_2_atomic instanceof TIterable)
                    && $type_1_atomic instanceof \Psalm\Type\Atomic\TList
                ) {
                    $type_2_param = $type_2_atomic->type_params[1];
                    $type_1_param = $type_1_atomic->type_param;
        
                    $type_2_param = self::filterTypeWithAnother(
                        $codebase,
                        $type_1_param,
                        $type_2_param,
                        $any_scalar_type_match_found
                    );
        
                    if ($type_2_param === null) {
                        return null;
                    }
        
                    if ($type_1_param->getId() !== $type_2_param->getId()) {
                        $type_1_atomic = $type_1_atomic->setTypeParam($type_2_param);
                    } elseif ($type_1_param !== $type_1_atomic->type_param) {
                        $type_1_atomic = $type_1_atomic->setTypeParam($type_1_param);
                    }
        
                    $matching_atomic_type = $type_1_atomic;
                    $atomic_comparison_results->type_coerced = true;
                }*/
        //we filter each property of a Keyed Array with the second part of standard iterables
        if (($type_2_atomic instanceof T_Array || $type_2_atomic instanceof T_Iterable) && $type_1_atomic instanceof T_Keyed_Array) {
            $type_2_param = $type_2_atomic->type_params[1];
            $type_1_properties = $type_1_atomic->properties;
            foreach ($type_1_properties as &$type_1_param) {
                $type_2_param = self::filter_type_with_another($codebase, $type_1_param, $type_2_param, $any_scalar_type_match_found);
                if ($type_2_param === null) {
                    return null;
                }
                if ($type_1_param->get_id() !== $type_2_param->get_id()) {
                    $type_1_param = $type_2_param->set_possibly_undefined($type_1_param->possibly_undefined);
                }
            }
            unset($type_1_param);
            if ($type_1_atomic->fallback_params === null) {
                $fallback_types = null;
            } else {
                //any fallback type is now the value of iterable
                $fallback_types = [$type_1_atomic->fallback_params[0], $type_2_param];
            }
            $matching_atomic_type = new T_Keyed_Array($type_1_properties, $type_1_atomic->class_strings, $fallback_types, $type_1_atomic->is_list, $type_1_atomic->from_docblock);
            $atomic_comparison_results->type_coerced = true;
        }
        //These partial match wouldn't have been handled by AtomicTypeComparator
        $new_range = null;
        if ($type_2_atomic instanceof T_Int_Range && $type_1_atomic instanceof T_Int_Range) {
            $new_range = T_Int_Range::intersect_int_ranges($type_1_atomic, $type_2_atomic);
        }
        if ($new_range !== null) {
            $matching_atomic_type = $new_range;
        }
        // Lowercase-string and non-empty-string are compatible but none is contained into the other completely
        if ($type_2_atomic instanceof T_Lowercase_String && $type_1_atomic instanceof T_Non_Empty_String || $type_2_atomic instanceof T_Non_Empty_String && $type_1_atomic instanceof T_Lowercase_String) {
            $matching_atomic_type = new T_Non_Empty_Lowercase_String();
        }
        // Lowercase-string and non-empty-string are compatible but none is contained into the other completely
        if ($type_2_atomic instanceof T_Lowercase_String && $type_1_atomic instanceof T_Non_Empty_Nonspecific_Literal_String || $type_2_atomic instanceof T_Non_Empty_Nonspecific_Literal_String && $type_1_atomic instanceof T_Lowercase_String) {
            $matching_atomic_type = new T_Non_Empty_Lowercase_String();
        }
        if (!$atomic_comparison_results->type_coerced && $atomic_comparison_results->scalar_type_match_found) {
            $any_scalar_type_match_found = true;
        }
        return $matching_atomic_type;
    }
    private static function refine_contained_atomic_with_another(Atomic $type_1_atomic, Atomic $type_2_atomic, Codebase $codebase, bool $type_coerced): ?Atomic
    {
        if ($type_coerced && $type_2_atomic::class === T_Named_Object::class && $type_1_atomic instanceof T_Generic_Object) {
            // this is a hack - it's not actually rigorous, as the params may be different
            return new T_Generic_Object($type_2_atomic->value, $type_1_atomic->type_params);
        }
        if ($type_2_atomic instanceof T_Named_Object && $type_1_atomic instanceof T_Template_Param && $type_1_atomic->as->has_object_type()) {
            $type_1_as_init = $type_1_atomic->as;
            $type_1_as = self::filter_type_with_another($codebase, $type_1_as_init, new Union([$type_2_atomic]));
            if ($type_1_as === null) {
                return null;
            }
            return $type_1_atomic->replace_as($type_1_as);
        }
        return $type_2_atomic;
    }
    /**
     * @param  TLiteralInt|TLiteralFloat|TLiteralString|TEnumCase $assertion_type
     * @param  string[]          $suppressed_issues
     */
    private static function handle_literal_equality(Statements_Analyzer $statements_analyzer, Assertion $assertion, Atomic $assertion_type, Union $existing_var_type, string $old_var_type_string, ?string $var_id, bool $negated, ?Code_Location $code_location, array $suppressed_issues): Union
    {
        $existing_var_atomic_types = [];
        foreach ($existing_var_type->get_atomic_types() as $existing_var_atomic_type) {
            if ($existing_var_atomic_type instanceof T_Class_Constant) {
                $expanded = Type_Expander::expand_atomic($statements_analyzer->get_codebase(), $existing_var_atomic_type, $existing_var_atomic_type->fq_classlike_name, $existing_var_atomic_type->fq_classlike_name, null, true, true);
                foreach ($expanded as $atomic_type) {
                    $existing_var_atomic_types[$atomic_type->get_key()] = $atomic_type;
                }
            } else {
                $existing_var_atomic_types[$existing_var_atomic_type->get_key()] = $existing_var_atomic_type;
            }
        }
        if ($assertion_type instanceof T_Literal_Int) {
            return self::handle_literal_equality_with_int($statements_analyzer, $assertion, $assertion_type, $existing_var_type, $existing_var_atomic_types, $old_var_type_string, $var_id, $negated, $code_location, $suppressed_issues);
        }
        if ($assertion_type instanceof T_Literal_String) {
            return self::handle_literal_equality_with_string($statements_analyzer, $assertion, $assertion_type, $existing_var_type, $existing_var_atomic_types, $old_var_type_string, $var_id, $negated, $code_location, $suppressed_issues);
        }
        if ($assertion_type instanceof T_Literal_Float) {
            return self::handle_literal_equality_with_float($statements_analyzer, $assertion, $assertion_type, $existing_var_type, $existing_var_atomic_types, $old_var_type_string, $var_id, $negated, $code_location, $suppressed_issues);
        }
        $fq_enum_name = $assertion_type->value;
        $case_name = $assertion_type->case_name;
        if ($existing_var_type->has_mixed()) {
            if ($assertion instanceof Is_Loosely_Equal) {
                return $existing_var_type;
            }
            return new Union([new T_Enum_Case($fq_enum_name, $case_name)]);
        }
        $can_be_equal = false;
        $redundant = true;
        $existing_var_type = $existing_var_type->get_builder();
        foreach ($existing_var_atomic_types as $atomic_key => $atomic_type) {
            if ($atomic_type::class === T_Named_Object::class && $atomic_type->value === $fq_enum_name) {
                $can_be_equal = true;
                $redundant = false;
                $existing_var_type->remove_type($atomic_key);
                $existing_var_type->add_type(new T_Enum_Case($fq_enum_name, $case_name));
            } elseif (Atomic_Type_Comparator::can_be_identical($statements_analyzer->get_codebase(), $atomic_type, $assertion_type)) {
                $can_be_equal = true;
                $redundant = $atomic_key === $assertion_type->get_key();
                $existing_var_type->remove_type($atomic_key);
                $existing_var_type->add_type(new T_Enum_Case($fq_enum_name, $case_name));
            } elseif ($atomic_key !== $assertion_type->get_key()) {
                $existing_var_type->remove_type($atomic_key);
                $redundant = false;
            } else {
                $can_be_equal = true;
            }
        }
        $existing_var_type = $existing_var_type->freeze();
        if ($var_id && $code_location && (!$can_be_equal || $redundant && count($existing_var_atomic_types) === 1)) {
            self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $var_id, $assertion, $can_be_equal, $negated, $code_location, $suppressed_issues);
        }
        return $existing_var_type;
    }
    /**
     * @param array<string, Atomic> $existing_var_atomic_types
     * @param string[]     $suppressed_issues
     */
    private static function handle_literal_equality_with_int(Statements_Analyzer $statements_analyzer, Assertion $assertion, T_Literal_Int $assertion_type, Union $existing_var_type, array $existing_var_atomic_types, string $old_var_type_string, ?string $var_id, bool $negated, ?Code_Location $code_location, array $suppressed_issues): Union
    {
        $value = $assertion_type->value;
        // we create the literal that is being asserted. We'll return this when we're sure this is the resulting type
        $literal_asserted_type = new Union([new T_Literal_Int($value)], ['from_docblock' => $existing_var_type->from_docblock]);
        $compatible_int_type = self::get_compatible_int_type($existing_var_type, $existing_var_atomic_types, $assertion_type, $assertion instanceof Is_Loosely_Equal);
        if ($compatible_int_type !== null) {
            return $compatible_int_type;
        }
        foreach ($existing_var_atomic_types as $existing_var_atomic_type) {
            if ($existing_var_atomic_type instanceof T_Int_Range && $existing_var_atomic_type->contains($value)) {
                return $literal_asserted_type;
            }
            if ($existing_var_atomic_type instanceof T_Literal_Int && $existing_var_atomic_type->value === $value) {
                //if we're here, we check that we had at least another type in the union, otherwise it's redundant
                if ($existing_var_type->is_single_int_literal()) {
                    if ($var_id && $code_location) {
                        self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $var_id, $assertion, true, $negated, $code_location, $suppressed_issues);
                    }
                    return $existing_var_type;
                }
                return $literal_asserted_type;
            }
            if ($existing_var_atomic_type instanceof T_Int && !$existing_var_atomic_type instanceof T_Literal_Int) {
                return $literal_asserted_type;
            }
            if ($existing_var_atomic_type instanceof T_Template_Param) {
                $compatible_int_type = self::get_compatible_int_type($existing_var_type, $existing_var_atomic_type->as->get_atomic_types(), $assertion_type, $assertion instanceof Is_Loosely_Equal);
                if ($compatible_int_type !== null) {
                    return $compatible_int_type;
                }
                $existing_var_atomic_type = $existing_var_atomic_type->replace_as(self::handle_literal_equality($statements_analyzer, $assertion, $assertion_type, $existing_var_atomic_type->as, $old_var_type_string, $var_id, $negated, $code_location, $suppressed_issues));
                return new Union([$existing_var_atomic_type]);
            }
            if ($assertion instanceof Is_Loosely_Equal && $existing_var_atomic_type instanceof T_Literal_Float && (int) $existing_var_atomic_type->value === $value) {
                return new Union([$existing_var_atomic_type]);
            }
            if ($assertion instanceof Is_Loosely_Equal && $existing_var_atomic_type instanceof T_Literal_String && (int) $existing_var_atomic_type->value === $value) {
                return new Union([$existing_var_atomic_type]);
            }
        }
        //here we'll accept non-literal type that *could* match on loose equality and return the original type
        foreach ($existing_var_atomic_types as $existing_var_atomic_type) {
            //here we'll accept non-literal type that *could* match on loose equality and return the original type
            if ($assertion instanceof Is_Loosely_Equal) {
                if ($existing_var_atomic_type instanceof T_String && !$existing_var_atomic_type instanceof T_Literal_String) {
                    return $existing_var_type;
                }
                if ($existing_var_atomic_type instanceof T_Float && !$existing_var_atomic_type instanceof T_Literal_Float) {
                    return $existing_var_type;
                }
            }
        }
        //if we're here, no type was eligible for the given literal. We'll emit an impossible error for this assertion
        if ($var_id && $code_location) {
            self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $var_id, $assertion, false, $negated, $code_location, $suppressed_issues);
        }
        return Type::get_never();
    }
    /**
     * @param array<string, Atomic> $existing_var_atomic_types
     * @param string[]     $suppressed_issues
     */
    private static function handle_literal_equality_with_string(Statements_Analyzer $statements_analyzer, Assertion $assertion, T_Literal_String $assertion_type, Union $existing_var_type, array $existing_var_atomic_types, string $old_var_type_string, ?string $var_id, bool $negated, ?Code_Location $code_location, array $suppressed_issues): Union
    {
        $value = $assertion_type->value;
        // we create the literal that is being asserted. We'll return this when we're sure this is the resulting type
        $literal_asserted_type_string = new Union([$assertion_type], ['from_docblock' => $existing_var_type->from_docblock]);
        $compatible_string_type = self::get_compatible_string_type($existing_var_type, $existing_var_atomic_types, $assertion_type, $assertion instanceof Is_Loosely_Equal);
        if ($compatible_string_type !== null) {
            return $compatible_string_type;
        }
        foreach ($existing_var_atomic_types as $existing_var_atomic_type) {
            if ($existing_var_atomic_type instanceof T_Literal_String && $existing_var_atomic_type->value === $value) {
                //if we're here, we check that we had at least another type in the union, otherwise it's redundant
                if ($existing_var_type->is_single_string_literal()) {
                    if ($var_id && $code_location) {
                        self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $var_id, $assertion, true, $negated, $code_location, $suppressed_issues);
                    }
                    return $existing_var_type;
                }
                return $literal_asserted_type_string;
            }
            if ($existing_var_atomic_type instanceof T_String && !$existing_var_atomic_type instanceof T_Literal_String) {
                return $literal_asserted_type_string;
            }
            if ($existing_var_atomic_type instanceof T_Template_Param) {
                $compatible_string_type = self::get_compatible_string_type($existing_var_type, $existing_var_atomic_type->as->get_atomic_types(), $assertion_type, $assertion instanceof Is_Loosely_Equal);
                if ($compatible_string_type !== null) {
                    return $compatible_string_type;
                }
                if ($existing_var_atomic_type->as->has_string()) {
                    return $literal_asserted_type_string;
                }
                $existing_var_atomic_type = $existing_var_atomic_type->replace_as(self::handle_literal_equality($statements_analyzer, $assertion, $assertion_type, $existing_var_atomic_type->as, $old_var_type_string, $var_id, $negated, $code_location, $suppressed_issues));
                return new Union([$existing_var_atomic_type]);
            }
            if ($assertion instanceof Is_Loosely_Equal && $existing_var_atomic_type instanceof T_Literal_Int && (string) $existing_var_atomic_type->value === $value) {
                return new Union([$existing_var_atomic_type]);
            }
            if ($assertion instanceof Is_Loosely_Equal && $existing_var_atomic_type instanceof T_Literal_Float && (string) $existing_var_atomic_type->value === $value) {
                return new Union([$existing_var_atomic_type]);
            }
        }
        //here we'll accept non-literal type that *could* match on loose equality and return the original type
        foreach ($existing_var_atomic_types as $existing_var_atomic_type) {
            //here we'll accept non-literal type that *could* match on loose equality and return the original type
            if ($assertion instanceof Is_Loosely_Equal) {
                if ($existing_var_atomic_type instanceof T_Int && !$existing_var_atomic_type instanceof T_Literal_Int) {
                    return $existing_var_type;
                }
                if ($existing_var_atomic_type instanceof T_Float && !$existing_var_atomic_type instanceof T_Literal_Float) {
                    return $existing_var_type;
                }
            }
        }
        //if we're here, no type was eligible for the given literal. We'll emit an impossible error for this assertion
        if ($var_id && $code_location) {
            self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $var_id, $assertion, false, $negated, $code_location, $suppressed_issues);
        }
        return Type::get_never();
    }
    /**
     * @param array<string, Atomic> $existing_var_atomic_types
     * @param string[]     $suppressed_issues
     */
    private static function handle_literal_equality_with_float(Statements_Analyzer $statements_analyzer, Assertion $assertion, T_Literal_Float $assertion_type, Union $existing_var_type, array $existing_var_atomic_types, string $old_var_type_string, ?string $var_id, bool $negated, ?Code_Location $code_location, array $suppressed_issues): Union
    {
        $value = $assertion_type->value;
        // we create the literal that is being asserted. We'll return this when we're sure this is the resulting type
        $literal_asserted_type = new Union([new T_Literal_Float($value)], ['from_docblock' => $existing_var_type->from_docblock]);
        $compatible_float_type = self::get_compatible_float_type($existing_var_type, $existing_var_atomic_types, $assertion_type, $assertion instanceof Is_Loosely_Equal);
        if ($compatible_float_type !== null) {
            return $compatible_float_type;
        }
        foreach ($existing_var_atomic_types as $existing_var_atomic_type) {
            if ($existing_var_atomic_type instanceof T_Literal_Float && $existing_var_atomic_type->value === $value) {
                //if we're here, we check that we had at least another type in the union, otherwise it's redundant
                if ($existing_var_type->is_single_float_literal()) {
                    if ($var_id && $code_location) {
                        self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $var_id, $assertion, true, $negated, $code_location, $suppressed_issues);
                    }
                    return $existing_var_type;
                }
                return $literal_asserted_type;
            }
            if ($existing_var_atomic_type instanceof T_Float && !$existing_var_atomic_type instanceof T_Literal_Float) {
                return $literal_asserted_type;
            }
            if ($existing_var_atomic_type instanceof T_Template_Param) {
                $compatible_float_type = self::get_compatible_float_type($existing_var_type, $existing_var_atomic_type->as->get_atomic_types(), $assertion_type, $assertion instanceof Is_Loosely_Equal);
                if ($compatible_float_type !== null) {
                    return $compatible_float_type;
                }
                if ($existing_var_atomic_type->as->has_float()) {
                    return $literal_asserted_type;
                }
                $existing_var_atomic_type = $existing_var_atomic_type->replace_as(self::handle_literal_equality($statements_analyzer, $assertion, $assertion_type, $existing_var_atomic_type->as, $old_var_type_string, $var_id, $negated, $code_location, $suppressed_issues));
                return new Union([$existing_var_atomic_type]);
            }
            if ($assertion instanceof Is_Loosely_Equal && $existing_var_atomic_type instanceof T_Literal_Int && (float) $existing_var_atomic_type->value === $value) {
                return new Union([$existing_var_atomic_type]);
            }
            if ($assertion instanceof Is_Loosely_Equal && $existing_var_atomic_type instanceof T_Literal_String && (float) $existing_var_atomic_type->value === $value) {
                return new Union([$existing_var_atomic_type]);
            }
        }
        //here we'll accept non-literal type that *could* match on loose equality and return the original type
        foreach ($existing_var_atomic_types as $existing_var_atomic_type) {
            if ($assertion instanceof Is_Loosely_Equal) {
                if ($existing_var_atomic_type instanceof T_Int && !$existing_var_atomic_type instanceof T_Literal_Int) {
                    return $existing_var_type;
                }
                if ($existing_var_atomic_type instanceof T_String && !$existing_var_atomic_type instanceof T_Literal_String) {
                    return $existing_var_type;
                }
            }
        }
        //if we're here, no type was eligible for the given literal. We'll emit an impossible error for this assertion
        if ($var_id && $code_location) {
            self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $var_id, $assertion, false, $negated, $code_location, $suppressed_issues);
        }
        return Type::get_never();
    }
    /**
     * @param array<string, Atomic> $existing_var_atomic_types
     */
    private static function get_compatible_int_type(Union $existing_var_type, array $existing_var_atomic_types, T_Literal_Int $assertion_type, bool $is_loose_equality): ?Union
    {
        foreach ($existing_var_atomic_types as $existing_var_atomic_type) {
            if ($existing_var_atomic_type instanceof T_Mixed || $existing_var_atomic_type instanceof T_Scalar || $existing_var_atomic_type instanceof T_Numeric || $existing_var_atomic_type instanceof T_Array_Key) {
                if ($is_loose_equality) {
                    return $existing_var_type;
                }
                return new Union([$assertion_type], ['from_docblock' => $existing_var_type->from_docblock]);
            }
        }
        return null;
    }
    /**
     * @param array<string, Atomic> $existing_var_atomic_types
     */
    private static function get_compatible_string_type(Union $existing_var_type, array $existing_var_atomic_types, T_Literal_String $assertion_type, bool $is_loose_equality): ?Union
    {
        foreach ($existing_var_atomic_types as $existing_var_atomic_type) {
            if ($existing_var_atomic_type instanceof T_Mixed || $existing_var_atomic_type instanceof T_Scalar || $existing_var_atomic_type instanceof T_Array_Key) {
                if ($is_loose_equality) {
                    return $existing_var_type;
                }
                return new Union([$assertion_type], ['from_docblock' => $existing_var_type->from_docblock]);
            }
        }
        return null;
    }
    /**
     * @param array<string, Atomic> $existing_var_atomic_types
     */
    private static function get_compatible_float_type(Union $existing_var_type, array $existing_var_atomic_types, T_Literal_Float $assertion_type, bool $is_loose_equality): ?Union
    {
        foreach ($existing_var_atomic_types as $existing_var_atomic_type) {
            if ($existing_var_atomic_type instanceof T_Mixed || $existing_var_atomic_type instanceof T_Scalar || $existing_var_atomic_type instanceof T_Numeric) {
                if ($is_loose_equality) {
                    return $existing_var_type;
                }
                return new Union([$assertion_type], ['from_docblock' => $existing_var_type->from_docblock]);
            }
        }
        return null;
    }
    /**
     * @param array<string>           $suppressed_issues
     * @return non-empty-list<Atomic>
     */
    private static function handle_is_a(Is_A_Class $assertion, Codebase $codebase, Union $existing_var_type, ?Code_Location $code_location, ?string $key, array $suppressed_issues, bool &$should_return): array
    {
        $allow_string_comparison = $assertion->allow_string;
        $assertion_type = $assertion->type;
        if ($existing_var_type->has_mixed()) {
            if (!$assertion_type instanceof T_Named_Object) {
                return [$assertion_type];
            }
            $types = [$assertion_type];
            if ($allow_string_comparison) {
                $types[] = new T_Class_String($assertion_type->value, $assertion_type);
            }
            $should_return = true;
            return $types;
        }
        $existing_has_object = $existing_var_type->has_object_type();
        $existing_has_string = $existing_var_type->has_string();
        if ($existing_has_object && !$existing_has_string) {
            if ($assertion_type instanceof T_Template_Param_Class) {
                return [new T_Template_Param($assertion_type->param_name, new Union([$assertion_type->as_type ?: new T_Object()]), $assertion_type->defining_class)];
            }
            return [$assertion_type];
        }
        if ($existing_has_string && !$existing_has_object) {
            if (!$allow_string_comparison && $code_location) {
                Issue_Buffer::maybe_add(new Type_Does_Not_Contain_Type('Cannot allow string comparison to object for ' . $key, $code_location, "no string comparison to {$key}"), $suppressed_issues);
                return [new T_Mixed()];
            }
            if (!$assertion_type instanceof T_Named_Object) {
                return [$assertion_type];
            }
            $new_type_has_interface_string = $codebase->interface_exists($assertion_type->value);
            $old_type_has_interface_string = false;
            foreach ($existing_var_type->get_atomic_types() as $existing_type_part) {
                if ($existing_type_part instanceof T_Class_String && $existing_type_part->as_type && $codebase->interface_exists($existing_type_part->as_type->value)) {
                    $old_type_has_interface_string = true;
                    break;
                }
            }
            $new_type = Type::get_class_string($assertion_type->value);
            if ($new_type_has_interface_string && !Union_Type_Comparator::is_contained_by($codebase, $existing_var_type, $new_type) || $old_type_has_interface_string && !Union_Type_Comparator::is_contained_by($codebase, $new_type, $existing_var_type)) {
                $new_type_part = $assertion_type;
                $acceptable_atomic_types = [];
                foreach ($existing_var_type->get_atomic_types() as $existing_var_type_part) {
                    if (!$existing_var_type_part instanceof T_Class_String) {
                        $acceptable_atomic_types = [];
                        break;
                    }
                    if (!$existing_var_type_part->as_type instanceof T_Named_Object) {
                        $acceptable_atomic_types = [];
                        break;
                    }
                    $existing_var_type_part = $existing_var_type_part->as_type;
                    if (Atomic_Type_Comparator::is_contained_by($codebase, $existing_var_type_part, $new_type_part)) {
                        $acceptable_atomic_types[] = $existing_var_type_part;
                        continue;
                    }
                    if ($codebase->class_exists($existing_var_type_part->value) || $codebase->interface_exists($existing_var_type_part->value)) {
                        $existing_var_type_part = $existing_var_type_part->add_intersection_type($new_type_part);
                        $acceptable_atomic_types[] = $existing_var_type_part;
                    }
                }
                if (count($acceptable_atomic_types) === 1) {
                    $should_return = true;
                    return [new T_Class_String('object', $acceptable_atomic_types[0])];
                }
            }
            return [$new_type->get_single_atomic()];
        }
        return [new T_Mixed()];
    }
}