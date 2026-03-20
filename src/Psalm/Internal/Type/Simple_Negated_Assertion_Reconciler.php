<?php

declare (strict_types=1);
namespace Psalm\Internal\Type;

use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\Internal\Codebase\Internal_Call_Map_Handler;
use Psalm\Internal\Type\Comparator\Callable_Type_Comparator;
use Psalm\Issue\Docblock_Type_Contradiction;
use Psalm\Issue\Redundant_Property_Initialization_Check;
use Psalm\Issue\Type_Does_Not_Contain_Type;
use Psalm\Issue_Buffer;
use Psalm\Storage\Assertion;
use Psalm\Storage\Assertion\Array_Key_Does_Not_Exist;
use Psalm\Storage\Assertion\Does_Not_Have_At_Least_Count;
use Psalm\Storage\Assertion\Does_Not_Have_Exact_Count;
use Psalm\Storage\Assertion\Empty_;
use Psalm\Storage\Assertion\Falsy;
use Psalm\Storage\Assertion\Is_Greater_Than_Or_Equal_To;
use Psalm\Storage\Assertion\Is_Less_Than_Or_Equal_To;
use Psalm\Storage\Assertion\Is_Not_Isset;
use Psalm\Storage\Assertion\Not_In_Array;
use Psalm\Storage\Assertion\Not_Non_Empty_Countable;
use Psalm\Type;
use Psalm\Type\Atomic\Scalar;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Array_Key;
use Psalm\Type\Atomic\T_Bool;
use Psalm\Type\Atomic\T_Callable;
use Psalm\Type\Atomic\T_Callable_Keyed_Array;
use Psalm\Type\Atomic\T_Callable_Object;
use Psalm\Type\Atomic\T_Callable_String;
use Psalm\Type\Atomic\T_Class_String;
use Psalm\Type\Atomic\T_Empty_Mixed;
use Psalm\Type\Atomic\T_Empty_Numeric;
use Psalm\Type\Atomic\T_Empty_Scalar;
use Psalm\Type\Atomic\T_False;
use Psalm\Type\Atomic\T_Float;
use Psalm\Type\Atomic\T_Generic_Object;
use Psalm\Type\Atomic\T_Int;
use Psalm\Type\Atomic\T_Int_Range;
use Psalm\Type\Atomic\T_Iterable;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Atomic\T_Literal_Float;
use Psalm\Type\Atomic\T_Literal_Int;
use Psalm\Type\Atomic\T_Literal_String;
use Psalm\Type\Atomic\T_Mixed;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Never;
use Psalm\Type\Atomic\T_Non_Empty_Lowercase_String;
use Psalm\Type\Atomic\T_Non_Empty_Nonspecific_Literal_String;
use Psalm\Type\Atomic\T_Non_Empty_String;
use Psalm\Type\Atomic\T_Null;
use Psalm\Type\Atomic\T_Numeric;
use Psalm\Type\Atomic\T_Object;
use Psalm\Type\Atomic\T_Resource;
use Psalm\Type\Atomic\T_Scalar;
use Psalm\Type\Atomic\T_String;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Atomic\T_True;
use Psalm\Type\Reconciler;
use Psalm\Type\Union;
use function assert;
use function max;
use function str_contains;
/**
 * @internal
 */
final class Simple_Negated_Assertion_Reconciler extends Reconciler
{
    /**
     * @param  string[]   $suppressed_issues
     * @param Reconciler::RECONCILIATION_* $failed_reconciliation
     */
    public static function reconcile(Codebase $codebase, Assertion $assertion, Union $existing_var_type, ?string $key = null, bool $negated = false, ?Code_Location $code_location = null, array $suppressed_issues = [], int &$failed_reconciliation = Reconciler::RECONCILIATION_EMPTY, bool $is_equality = false, bool $inside_loop = false): ?Union
    {
        $old_var_type_string = $existing_var_type->get_id();
        if ($assertion instanceof Is_Not_Isset) {
            if ($existing_var_type->possibly_undefined) {
                return Type::get_never();
            }
            if (!$existing_var_type->is_nullable() && $key && !str_contains($key, '[') && (!$existing_var_type->has_mixed() || $existing_var_type->is_always_truthy())) {
                if ($code_location) {
                    if ($existing_var_type->from_static_property) {
                        Issue_Buffer::maybe_add(new Redundant_Property_Initialization_Check('Static property ' . $key . ' with type ' . $existing_var_type . ' has unexpected isset check — should it be nullable?', $code_location), $suppressed_issues);
                    } elseif ($existing_var_type->from_property) {
                        Issue_Buffer::maybe_add(new Redundant_Property_Initialization_Check('Property ' . $key . ' with type ' . $existing_var_type . ' should already be set in the constructor', $code_location), $suppressed_issues);
                    } elseif ($existing_var_type->from_docblock) {
                        Issue_Buffer::maybe_add(new Docblock_Type_Contradiction('Cannot resolve types for ' . $key . ' with docblock-defined type ' . $existing_var_type . ' and !isset assertion', $code_location, 'cannot resolve !isset ' . $existing_var_type . ' ' . $key), $suppressed_issues);
                    } else {
                        Issue_Buffer::maybe_add(new Type_Does_Not_Contain_Type('Cannot resolve types for ' . $key . ' with type ' . $existing_var_type . ' and !isset assertion', $code_location, 'cannot resolve !isset ' . $existing_var_type . ' ' . $key), $suppressed_issues);
                    }
                }
                return Type::get_never();
            }
            return Type::get_null();
        }
        if ($assertion instanceof Array_Key_Does_Not_Exist) {
            return Type::get_never();
        }
        if ($assertion instanceof Not_In_Array) {
            $new_var_type = $assertion->type;
            $intersection = Type::intersect_union_types($new_var_type, $existing_var_type, $codebase);
            if ($intersection === null) {
                if ($key && $code_location) {
                    self::trigger_issue_for_impossible($existing_var_type, $existing_var_type->get_id(), $key, $assertion, true, $negated, $code_location, $suppressed_issues);
                }
                $failed_reconciliation = Reconciler::RECONCILIATION_EMPTY;
            }
            return $existing_var_type;
        }
        if ($assertion instanceof Falsy || $assertion instanceof Empty_) {
            return self::reconcile_falsy_or_empty($assertion, $existing_var_type, $key, $negated, $code_location, $suppressed_issues, $failed_reconciliation, false);
        }
        if ($assertion instanceof Not_Non_Empty_Countable) {
            return self::reconcile_not_non_empty_countable($assertion, $existing_var_type, $key, $negated, $code_location, $suppressed_issues, $is_equality, null);
        }
        if ($assertion instanceof Does_Not_Have_At_Least_Count) {
            return self::reconcile_not_non_empty_countable($assertion, $existing_var_type, $key, $negated, $code_location, $suppressed_issues, $is_equality, $assertion->count);
        }
        if ($assertion instanceof Does_Not_Have_Exact_Count) {
            return $existing_var_type;
        }
        if ($assertion instanceof Is_Less_Than_Or_Equal_To) {
            return self::reconcile_is_less_than_or_equal_to($assertion, $existing_var_type, $inside_loop, $old_var_type_string, $key, $negated, $code_location, $suppressed_issues);
        }
        if ($assertion instanceof Is_Greater_Than_Or_Equal_To) {
            return self::reconcile_is_greater_than_or_equal_to($assertion, $existing_var_type, $inside_loop, $old_var_type_string, $key, $negated, $code_location, $suppressed_issues);
        }
        $assertion_type = $assertion->get_atomic_type();
        if ($assertion_type instanceof T_Object && !$existing_var_type->has_mixed()) {
            return self::reconcile_object($assertion, $existing_var_type, $key, $negated, $code_location, $suppressed_issues, $failed_reconciliation, $is_equality);
        }
        if ($assertion_type instanceof T_Scalar && !$existing_var_type->has_mixed()) {
            return self::reconcile_scalar($assertion, $existing_var_type, $key, $negated, $code_location, $suppressed_issues, $failed_reconciliation, $is_equality);
        }
        if ($assertion_type instanceof T_Resource && !$existing_var_type->has_mixed()) {
            return self::reconcile_resource($assertion, $existing_var_type, $key, $negated, $code_location, $suppressed_issues, $failed_reconciliation, $is_equality);
        }
        if ($assertion_type && $assertion_type::class === T_Bool::class && !$existing_var_type->has_mixed()) {
            return self::reconcile_bool($assertion, $existing_var_type, $key, $negated, $code_location, $suppressed_issues, $failed_reconciliation, $is_equality);
        }
        if ($assertion_type instanceof T_Numeric && !$existing_var_type->has_mixed()) {
            return self::reconcile_numeric($assertion, $existing_var_type, $key, $negated, $code_location, $suppressed_issues, $failed_reconciliation, $is_equality);
        }
        if ($assertion_type instanceof T_Float && !$existing_var_type->has_mixed()) {
            return self::reconcile_float($assertion, $existing_var_type, $key, $negated, $code_location, $suppressed_issues, $failed_reconciliation, $is_equality);
        }
        if ($assertion_type && $assertion_type::class === T_Int::class && !$existing_var_type->has_mixed()) {
            return self::reconcile_int($assertion, $existing_var_type, $key, $negated, $code_location, $suppressed_issues, $failed_reconciliation, $is_equality);
        }
        if ($assertion_type && $assertion_type::class === T_String::class && !$existing_var_type->has_mixed()) {
            return self::reconcile_string($assertion, $existing_var_type, $key, $negated, $code_location, $suppressed_issues, $failed_reconciliation, $is_equality);
        }
        if ($assertion_type instanceof T_Array && !$existing_var_type->has_mixed() && $assertion_type->type_params[0]->is_array_key() && $assertion_type->type_params[1]->is_mixed()) {
            return self::reconcile_array($assertion, $existing_var_type, $key, $negated, $code_location, $suppressed_issues, $failed_reconciliation, $is_equality);
        }
        if ($assertion_type instanceof T_Null && !$existing_var_type->has_mixed()) {
            return self::reconcile_null($assertion, $existing_var_type, $key, $negated, $code_location, $suppressed_issues, $failed_reconciliation, $is_equality);
        }
        if ($assertion_type instanceof T_False && !$existing_var_type->has_mixed()) {
            return self::reconcile_false($assertion, $existing_var_type, $key, $negated, $code_location, $suppressed_issues, $failed_reconciliation, $is_equality);
        }
        if ($assertion_type instanceof T_True && !$existing_var_type->has_mixed()) {
            return self::reconcile_true($assertion, $existing_var_type, $key, $negated, $code_location, $suppressed_issues, $failed_reconciliation, $is_equality);
        }
        if ($assertion_type instanceof T_Callable) {
            return self::reconcile_callable($existing_var_type, $codebase, $assertion_type);
        }
        return null;
    }
    private static function reconcile_callable(Union $existing_var_type, Codebase $codebase, T_Callable $assertion_type): Union
    {
        $existing_var_type = $existing_var_type->get_builder();
        foreach ($existing_var_type->get_atomic_types() as $atomic_key => $type) {
            if ($type instanceof T_Literal_String && Internal_Call_Map_Handler::in_call_map($type->value)) {
                $existing_var_type->remove_type($atomic_key);
                continue;
            }
            if ($type->is_callable_type()) {
                $existing_var_type->remove_type($atomic_key);
                continue;
            }
            $candidate_callable = Callable_Type_Comparator::get_callable_from_atomic($codebase, $type, $assertion_type);
            if ($candidate_callable) {
                $existing_var_type->remove_type($atomic_key);
            }
        }
        return $existing_var_type->freeze();
    }
    /**
     * @param   string[]  $suppressed_issues
     * @param Reconciler::RECONCILIATION_* $failed_reconciliation
     */
    private static function reconcile_bool(Assertion $assertion, Union $existing_var_type, ?string $key, bool $negated, ?Code_Location $code_location, array $suppressed_issues, int &$failed_reconciliation, bool $is_equality): Union
    {
        $old_var_type_string = $existing_var_type->get_id();
        $non_bool_types = [];
        $redundant = true;
        foreach ($existing_var_type->get_atomic_types() as $type) {
            if ($type instanceof T_Template_Param) {
                if (!$is_equality && !$type->as->is_mixed()) {
                    $template_did_fail = 0;
                    $type = $type->replace_as(self::reconcile_bool($assertion, $type->as, null, false, null, $suppressed_issues, $template_did_fail, $is_equality));
                    $redundant = false;
                    if (!$template_did_fail) {
                        $non_bool_types[] = $type;
                    }
                } else {
                    $redundant = false;
                    $non_bool_types[] = $type;
                }
            } elseif (!$type instanceof T_Bool || $is_equality && $type::class === T_Bool::class) {
                if ($type instanceof T_Scalar) {
                    $redundant = false;
                    $non_bool_types[] = new T_String();
                    $non_bool_types[] = new T_Int();
                    $non_bool_types[] = new T_Float();
                } else {
                    $non_bool_types[] = $type;
                }
            } else {
                $redundant = false;
            }
        }
        if ($redundant || !$non_bool_types) {
            if ($key && $code_location && !$is_equality) {
                self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $key, $assertion, $redundant, $negated, $code_location, $suppressed_issues);
            }
            if ($redundant) {
                $failed_reconciliation = Reconciler::RECONCILIATION_REDUNDANT;
            }
        }
        if ($non_bool_types) {
            return new Union($non_bool_types);
        }
        $failed_reconciliation = Reconciler::RECONCILIATION_EMPTY;
        return Type::get_never();
    }
    /**
     * @param   string[]  $suppressed_issues
     */
    private static function reconcile_not_non_empty_countable(Assertion $assertion, Union $existing_var_type, ?string $key, bool $negated, ?Code_Location $code_location, array $suppressed_issues, bool $is_equality, ?int $count): Union
    {
        $existing_var_type = $existing_var_type->get_builder();
        $old_var_type_string = $existing_var_type->get_id();
        $existing_var_atomic_types = $existing_var_type->get_atomic_types();
        if (isset($existing_var_atomic_types['array'])) {
            $array_atomic_type = $existing_var_type->get_array();
            $redundant = true;
            if ($array_atomic_type instanceof T_Keyed_Array) {
                if ($count !== null) {
                    $prop_max_count = $array_atomic_type->get_max_count();
                    $prop_min_count = $array_atomic_type->get_min_count();
                    // !(count($a) >= 3)
                    // count($a) < 3
                    // We're asserting that count($a) < $count
                    // If it's impossible, remove the type
                    // If it's possible but redundant, mark as redundant
                    // If it's possible, mark as not redundant
                    // Impossible because count($a) >= $count always
                    if ($prop_min_count >= $count) {
                        $redundant = false;
                        $existing_var_type->remove_type('array');
                        // Redundant because count($a) < $count always
                    } elseif ($prop_max_count && $prop_max_count < $count) {
                        $redundant = true;
                        // Possible
                    } else {
                        if ($array_atomic_type->is_list && $array_atomic_type->fallback_params) {
                            $properties = [];
                            for ($x = 0; $x < $count - 1; $x++) {
                                $properties[] = $array_atomic_type->properties[$x] ?? $array_atomic_type->fallback_params[1]->set_possibly_undefined(true);
                            }
                            $existing_var_type->remove_type('array');
                            if (!$properties) {
                                $existing_var_type->add_type(Type::get_empty_array_atomic());
                            } else {
                                $existing_var_type->add_type(new T_Keyed_Array($properties, null, null, true, $array_atomic_type->from_docblock));
                            }
                        }
                        $redundant = false;
                    }
                } else if ($array_atomic_type->is_non_empty()) {
                    // Impossible, never empty
                    $redundant = false;
                    $existing_var_type->remove_type('array');
                } else {
                    // Possible, can be empty
                    $redundant = false;
                    $existing_var_type->remove_type('array');
                    $existing_var_type->add_type(Type::get_empty_array_atomic());
                }
            } elseif (!$array_atomic_type instanceof T_Array || !$array_atomic_type->is_empty_array()) {
                $redundant = false;
                if (!$count) {
                    $existing_var_type->add_type(new T_Array([new Union([new T_Never()]), new Union([new T_Never()])]));
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
    private static function reconcile_null(Assertion $assertion, Union $existing_var_type, ?string $key, bool $negated, ?Code_Location $code_location, array $suppressed_issues, int &$failed_reconciliation, bool $is_equality): Union
    {
        $types = $existing_var_type->get_atomic_types();
        $old_var_type_string = $existing_var_type->get_id();
        $redundant = true;
        if (isset($types['null'])) {
            $redundant = false;
            unset($types['null']);
        }
        foreach ($types as &$type) {
            if ($type instanceof T_Template_Param) {
                $new = $type->replace_as(self::reconcile_null($assertion, $type->as, null, false, null, $suppressed_issues, $failed_reconciliation, $is_equality));
                //if ($new !== $type) {
                //    $redundant = false;
                //}
                // TODO: This is technically wrong, but for some reason we get a
                // duplicated assertion here when using template types.
                $redundant = false;
                $type = $new;
            }
        }
        unset($type);
        if ($redundant || !$types) {
            if ($key && $code_location && !$is_equality) {
                self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $key, $assertion, $redundant, $negated, $code_location, $suppressed_issues);
            }
            if ($redundant) {
                $failed_reconciliation = Reconciler::RECONCILIATION_REDUNDANT;
            }
        }
        if ($types) {
            return $existing_var_type->set_types($types);
        }
        $failed_reconciliation = Reconciler::RECONCILIATION_EMPTY;
        return Type::get_never();
    }
    /**
     * @param   string[]  $suppressed_issues
     * @param Reconciler::RECONCILIATION_* $failed_reconciliation
     */
    private static function reconcile_false(Assertion $assertion, Union $existing_var_type, ?string $key, bool $negated, ?Code_Location $code_location, array $suppressed_issues, int &$failed_reconciliation, bool $is_equality): Union
    {
        $types = $existing_var_type->get_atomic_types();
        $old_var_type_string = $existing_var_type->get_id();
        $redundant = true;
        if (isset($types['scalar'])) {
            $redundant = false;
        }
        if (isset($types['bool'])) {
            $redundant = false;
            $types[] = new T_True();
            unset($types['bool']);
        }
        if (isset($types['false'])) {
            $redundant = false;
            unset($types['false']);
        }
        foreach ($types as &$type) {
            if ($type instanceof T_Template_Param) {
                $new = $type->replace_as(self::reconcile_false($assertion, $type->as, null, false, null, $suppressed_issues, $failed_reconciliation, $is_equality));
                if ($new !== $type) {
                    $redundant = false;
                }
                $type = $new;
            }
        }
        unset($type);
        if ($redundant || !$types) {
            if ($key && $code_location && !$is_equality) {
                self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $key, $assertion, $redundant, $negated, $code_location, $suppressed_issues);
            }
            if ($redundant) {
                $failed_reconciliation = Reconciler::RECONCILIATION_REDUNDANT;
            }
        }
        if ($types) {
            return $existing_var_type->set_types($types);
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
        $types = $existing_var_type->get_atomic_types();
        $old_var_type_string = $existing_var_type->get_id();
        $redundant = true;
        if (isset($types['scalar'])) {
            $redundant = false;
        }
        if (isset($types['bool'])) {
            $redundant = false;
            $types[] = new T_False();
            unset($types['bool']);
        }
        if (isset($types['true'])) {
            $redundant = false;
            unset($types['true']);
        }
        foreach ($types as &$type) {
            if ($type instanceof T_Template_Param) {
                $new = $type->replace_as(self::reconcile_true($assertion, $type->as, null, false, null, $suppressed_issues, $failed_reconciliation, $is_equality));
                if ($new !== $type) {
                    $redundant = false;
                }
                $type = $new;
            }
        }
        unset($type);
        if ($redundant || !$types) {
            if ($key && $code_location && !$is_equality) {
                self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $key, $assertion, $redundant, $negated, $code_location, $suppressed_issues);
            }
            if ($redundant) {
                $failed_reconciliation = Reconciler::RECONCILIATION_REDUNDANT;
            }
        }
        if ($types) {
            return $existing_var_type->set_types($types);
        }
        $failed_reconciliation = Reconciler::RECONCILIATION_EMPTY;
        return Type::get_never();
    }
    /**
     * @param   Falsy|Empty_ $assertion
     * @param   string[]  $suppressed_issues
     * @param Reconciler::RECONCILIATION_* $failed_reconciliation
     */
    private static function reconcile_falsy_or_empty(Assertion $assertion, Union $existing_var_type, ?string $key, bool $negated, ?Code_Location $code_location, array $suppressed_issues, int &$failed_reconciliation, bool $recursive_check): Union
    {
        $existing_var_type = $existing_var_type->get_builder();
        $old_var_type_string = $existing_var_type->get_id();
        $redundant = !($existing_var_type->possibly_undefined || $existing_var_type->possibly_undefined_from_try);
        foreach ($existing_var_type->get_atomic_types() as $existing_var_type_key => $existing_var_type_part) {
            //if any atomic in the union is either always truthy, we remove it. If not always falsy, we mark the check
            //as not redundant.
            if (!$existing_var_type->possibly_undefined && !$existing_var_type->possibly_undefined_from_try && $existing_var_type_part->is_truthy()) {
                $redundant = false;
                $existing_var_type->remove_type($existing_var_type_key);
            } elseif (!$existing_var_type_part->is_falsy()) {
                $redundant = false;
            }
        }
        if (!$redundant && $existing_var_type->is_union_empty()) {
            //every type was removed, this is an impossible assertion
            if ($code_location && $key && !$recursive_check) {
                self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $key, $assertion, false, $negated, $code_location, $suppressed_issues);
            }
            $failed_reconciliation = 2;
            return Type::get_never();
        }
        if ($redundant) {
            //nothing was removed, this is a redundant assertion
            if ($code_location && $key && !$recursive_check) {
                self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $key, $assertion, true, $negated, $code_location, $suppressed_issues);
            }
            $failed_reconciliation = 1;
            return $existing_var_type->freeze();
        }
        if ($existing_var_type->has_type('bool')) {
            $existing_var_type->remove_type('bool');
            $existing_var_type->add_type(new T_False());
        }
        if ($existing_var_type->has_array()) {
            $existing_var_type->remove_type('array');
            $existing_var_type->add_type(new T_Array([new Union([new T_Never()]), new Union([new T_Never()])]));
        }
        if ($existing_var_type->has_mixed()) {
            $mixed_atomic_type = $existing_var_type->get_atomic_types()['mixed'];
            if ($mixed_atomic_type::class === T_Mixed::class) {
                $existing_var_type->remove_type('mixed');
                $existing_var_type->add_type(new T_Empty_Mixed());
            }
        }
        if ($existing_var_type->has_scalar()) {
            $scalar_atomic_type = $existing_var_type->get_atomic_types()['scalar'];
            if ($scalar_atomic_type::class === T_Scalar::class) {
                $existing_var_type->remove_type('scalar');
                $existing_var_type->add_type(new T_Empty_Scalar());
            }
        }
        if ($existing_var_type->has_type('string')) {
            $string_atomic_type = $existing_var_type->get_atomic_types()['string'];
            if ($string_atomic_type::class === T_String::class) {
                $existing_var_type->remove_type('string');
                $existing_var_type->add_type(Type::get_atomic_string_from_literal(''));
                $existing_var_type->add_type(Type::get_atomic_string_from_literal('0'));
            } elseif ($string_atomic_type::class === T_Non_Empty_String::class) {
                $existing_var_type->remove_type('string');
                $existing_var_type->add_type(Type::get_atomic_string_from_literal('0'));
            } elseif ($string_atomic_type::class === T_Non_Empty_Lowercase_String::class) {
                $existing_var_type->remove_type('string');
                $existing_var_type->add_type(Type::get_atomic_string_from_literal('0'));
            } elseif ($string_atomic_type::class === T_Non_Empty_Nonspecific_Literal_String::class) {
                $existing_var_type->remove_type('string');
                $existing_var_type->add_type(Type::get_atomic_string_from_literal('0'));
            }
        }
        if ($existing_var_type->has_int()) {
            $existing_range_types = $existing_var_type->get_range_ints();
            if ($existing_range_types) {
                foreach ($existing_range_types as $int_key => $literal_type) {
                    if ($literal_type->contains(0)) {
                        $existing_var_type->remove_type($int_key);
                        $existing_var_type->add_type(new T_Literal_Int(0));
                    }
                }
            } else {
                $existing_var_type->remove_type('int');
                $existing_var_type->add_type(new T_Literal_Int(0));
            }
        }
        if ($existing_var_type->has_float()) {
            $existing_var_type->remove_type('float');
            $existing_var_type->add_type(new T_Literal_Float(0.0));
        }
        if ($existing_var_type->has_numeric()) {
            $existing_var_type->remove_type('numeric');
            $existing_var_type->add_type(new T_Empty_Numeric());
        }
        foreach ($existing_var_type->get_atomic_types() as $type_key => $existing_var_atomic_type) {
            if (!$existing_var_atomic_type instanceof T_Template_Param) {
                continue;
            }
            if ($existing_var_atomic_type->as->is_mixed()) {
                continue;
            }
            $template_did_fail = 0;
            $existing_var_atomic_type = $existing_var_atomic_type->replace_as(self::reconcile_falsy_or_empty($assertion, $existing_var_atomic_type->as, $key, $negated, $code_location, $suppressed_issues, $template_did_fail, $recursive_check));
            if (!$template_did_fail) {
                $existing_var_type->remove_type($type_key);
                $existing_var_type->add_type($existing_var_atomic_type);
            }
        }
        /** @psalm-suppress RedundantCondition Psalm bug */
        assert(!$existing_var_type->is_union_empty());
        return $existing_var_type->freeze();
    }
    /**
     * @param   string[]  $suppressed_issues
     * @param Reconciler::RECONCILIATION_* $failed_reconciliation
     */
    private static function reconcile_scalar(Assertion $assertion, Union $existing_var_type, ?string $key, bool $negated, ?Code_Location $code_location, array $suppressed_issues, int &$failed_reconciliation, bool $is_equality): Union
    {
        $old_var_type_string = $existing_var_type->get_id();
        $non_scalar_types = [];
        $redundant = true;
        foreach ($existing_var_type->get_atomic_types() as $type) {
            if ($type instanceof T_Template_Param) {
                if (!$is_equality && !$type->as->is_mixed()) {
                    $template_did_fail = 0;
                    $type = $type->replace_as(self::reconcile_scalar($assertion, $type->as, null, false, null, $suppressed_issues, $template_did_fail, $is_equality));
                    $redundant = false;
                    if (!$template_did_fail) {
                        $non_scalar_types[] = $type;
                    }
                } else {
                    $redundant = false;
                    $non_scalar_types[] = $type;
                }
            } elseif (!$type instanceof Scalar) {
                $non_scalar_types[] = $type;
            } else {
                $redundant = false;
                if ($is_equality) {
                    $non_scalar_types[] = $type;
                }
            }
        }
        if ($redundant || !$non_scalar_types) {
            if ($key && $code_location) {
                self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $key, $assertion, $redundant, $negated, $code_location, $suppressed_issues);
            }
            if ($redundant) {
                $failed_reconciliation = Reconciler::RECONCILIATION_REDUNDANT;
            }
        }
        if ($non_scalar_types) {
            return new Union($non_scalar_types, ['ignore_falsable_issues' => $existing_var_type->ignore_falsable_issues, 'ignore_nullable_issues' => $existing_var_type->ignore_nullable_issues, 'from_docblock' => $existing_var_type->from_docblock]);
        }
        $failed_reconciliation = Reconciler::RECONCILIATION_EMPTY;
        return Type::get_never();
    }
    /**
     * @param   string[]  $suppressed_issues
     * @param Reconciler::RECONCILIATION_* $failed_reconciliation
     */
    private static function reconcile_object(Assertion $assertion, Union $existing_var_type, ?string $key, bool $negated, ?Code_Location $code_location, array $suppressed_issues, int &$failed_reconciliation, bool $is_equality): Union
    {
        $old_var_type_string = $existing_var_type->get_id();
        $non_object_types = [];
        $redundant = true;
        foreach ($existing_var_type->get_atomic_types() as $type) {
            if ($type instanceof T_Template_Param) {
                if (!$is_equality && !$type->as->is_mixed()) {
                    $template_did_fail = 0;
                    $type = $type->replace_as(self::reconcile_object($assertion, $type->as, null, false, null, $suppressed_issues, $template_did_fail, $is_equality));
                    $redundant = false;
                    if (!$template_did_fail) {
                        $non_object_types[] = $type;
                    }
                } else {
                    $redundant = false;
                    $non_object_types[] = $type;
                }
            } elseif ($type instanceof T_Callable) {
                $non_object_types[] = new T_Callable_Keyed_Array([new Union([new T_Class_String(), new T_Object()]), Type::get_non_empty_string()]);
                $non_object_types[] = new T_Callable_String();
                $redundant = false;
            } elseif ($type instanceof T_Iterable) {
                $params = $type->type_params;
                $params[0] = self::refine_array_key($params[0]);
                $non_object_types[] = new T_Array($params);
                $redundant = false;
            } elseif (!$type->is_object_type()) {
                $non_object_types[] = $type;
            } else {
                $redundant = false;
                if ($is_equality) {
                    $non_object_types[] = $type;
                }
            }
        }
        if (!$non_object_types || $redundant) {
            if ($key && $code_location) {
                self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $key, $assertion, $redundant, $negated, $code_location, $suppressed_issues);
            }
            if ($redundant) {
                $failed_reconciliation = Reconciler::RECONCILIATION_REDUNDANT;
            }
        }
        if ($non_object_types) {
            return new Union($non_object_types, ['ignore_falsable_issues' => $existing_var_type->ignore_falsable_issues, 'ignore_nullable_issues' => $existing_var_type->ignore_nullable_issues, 'from_docblock' => $existing_var_type->from_docblock]);
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
        $old_var_type_string = $existing_var_type->get_id();
        $non_numeric_types = [];
        $redundant = !($existing_var_type->has_string() || $existing_var_type->has_scalar());
        foreach ($existing_var_type->get_atomic_types() as $type) {
            if ($type instanceof T_Template_Param) {
                if (!$is_equality && !$type->as->is_mixed()) {
                    $template_did_fail = 0;
                    $type = $type->replace_as(self::reconcile_numeric($assertion, $type->as, null, false, null, $suppressed_issues, $template_did_fail, $is_equality));
                    $redundant = false;
                    if (!$template_did_fail) {
                        $non_numeric_types[] = $type;
                    }
                } else {
                    $redundant = false;
                    $non_numeric_types[] = $type;
                }
            } elseif ($type instanceof T_Array_Key) {
                $redundant = false;
                $non_numeric_types[] = new T_String();
            } elseif ($type instanceof T_Scalar) {
                $redundant = false;
                $non_numeric_types[] = new T_String();
                $non_numeric_types[] = new T_Bool();
            } elseif (!$type->is_numeric_type()) {
                $non_numeric_types[] = $type;
            } else {
                $redundant = false;
                if ($is_equality) {
                    $non_numeric_types[] = $type;
                }
            }
        }
        if (!$non_numeric_types || $redundant) {
            if ($key && $code_location && !$is_equality) {
                self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $key, $assertion, $redundant, $negated, $code_location, $suppressed_issues);
            }
            if ($redundant) {
                $failed_reconciliation = Reconciler::RECONCILIATION_REDUNDANT;
            }
        }
        if ($non_numeric_types) {
            return new Union($non_numeric_types, ['ignore_falsable_issues' => $existing_var_type->ignore_falsable_issues, 'ignore_nullable_issues' => $existing_var_type->ignore_nullable_issues, 'from_docblock' => $existing_var_type->from_docblock]);
        }
        $failed_reconciliation = Reconciler::RECONCILIATION_EMPTY;
        return Type::get_never();
    }
    /**
     * @param   string[]  $suppressed_issues
     * @param Reconciler::RECONCILIATION_* $failed_reconciliation
     */
    private static function reconcile_int(Assertion $assertion, Union $existing_var_type, ?string $key, bool $negated, ?Code_Location $code_location, array $suppressed_issues, int &$failed_reconciliation, bool $is_equality): Union
    {
        $old_var_type_string = $existing_var_type->get_id();
        $non_int_types = [];
        $redundant = true;
        foreach ($existing_var_type->get_atomic_types() as $type) {
            if ($type instanceof T_Template_Param) {
                if (!$is_equality && !$type->as->is_mixed()) {
                    $template_did_fail = 0;
                    $type = $type->replace_as(self::reconcile_int($assertion, $type->as, null, false, null, $suppressed_issues, $template_did_fail, $is_equality));
                    $redundant = false;
                    if (!$template_did_fail) {
                        $non_int_types[] = $type;
                    }
                } else {
                    $redundant = false;
                    $non_int_types[] = $type;
                }
            } elseif ($type instanceof T_Array_Key) {
                $redundant = false;
                $non_int_types[] = new T_String();
            } elseif ($type instanceof T_Scalar) {
                $redundant = false;
                $non_int_types[] = new T_String();
                $non_int_types[] = new T_Float();
                $non_int_types[] = new T_Bool();
            } elseif ($type instanceof T_Int) {
                $redundant = false;
                if ($is_equality) {
                    $non_int_types[] = $type;
                } elseif ($existing_var_type->from_calculation) {
                    $non_int_types[] = new T_Float();
                }
            } elseif ($type instanceof T_Numeric) {
                $redundant = false;
                $non_int_types[] = new T_String();
                $non_int_types[] = new T_Float();
            } else {
                $non_int_types[] = $type;
            }
        }
        if (!$non_int_types || $redundant) {
            if ($key && $code_location && !$is_equality) {
                self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $key, $assertion, $redundant, $negated, $code_location, $suppressed_issues);
            }
            if ($redundant) {
                $failed_reconciliation = Reconciler::RECONCILIATION_REDUNDANT;
            }
        }
        if ($non_int_types) {
            return new Union($non_int_types, ['ignore_falsable_issues' => $existing_var_type->ignore_falsable_issues, 'ignore_nullable_issues' => $existing_var_type->ignore_nullable_issues, 'from_docblock' => $existing_var_type->from_docblock]);
        }
        $failed_reconciliation = Reconciler::RECONCILIATION_EMPTY;
        return Type::get_never();
    }
    /**
     * @param   string[]  $suppressed_issues
     * @param Reconciler::RECONCILIATION_* $failed_reconciliation
     */
    private static function reconcile_float(Assertion $assertion, Union $existing_var_type, ?string $key, bool $negated, ?Code_Location $code_location, array $suppressed_issues, int &$failed_reconciliation, bool $is_equality): Union
    {
        $old_var_type_string = $existing_var_type->get_id();
        $non_float_types = [];
        $redundant = true;
        foreach ($existing_var_type->get_atomic_types() as $type) {
            if ($type instanceof T_Template_Param) {
                if (!$is_equality && !$type->as->is_mixed()) {
                    $template_did_fail = 0;
                    $type = $type->replace_as(self::reconcile_float($assertion, $type->as, null, false, null, $suppressed_issues, $template_did_fail, $is_equality));
                    $redundant = false;
                    if (!$template_did_fail) {
                        $non_float_types[] = $type;
                    }
                } else {
                    $redundant = false;
                    $non_float_types[] = $type;
                }
            } elseif ($type instanceof T_Scalar) {
                $redundant = false;
                $non_float_types[] = new T_String();
                $non_float_types[] = new T_Int();
                $non_float_types[] = new T_Bool();
            } elseif ($type instanceof T_Float) {
                $redundant = false;
                if ($is_equality) {
                    $non_float_types[] = $type;
                }
            } elseif ($type instanceof T_Numeric) {
                $redundant = false;
                $non_float_types[] = new T_String();
                $non_float_types[] = new T_Int();
            } else {
                $non_float_types[] = $type;
            }
        }
        if (!$non_float_types || $redundant) {
            if ($key && $code_location && !$is_equality) {
                self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $key, $assertion, $redundant, $negated, $code_location, $suppressed_issues);
            }
            if ($redundant) {
                $failed_reconciliation = Reconciler::RECONCILIATION_REDUNDANT;
            }
        }
        if ($non_float_types) {
            return new Union($non_float_types, ['ignore_falsable_issues' => $existing_var_type->ignore_falsable_issues, 'ignore_nullable_issues' => $existing_var_type->ignore_nullable_issues, 'from_docblock' => $existing_var_type->from_docblock]);
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
        $non_string_types = [];
        $redundant = !$existing_var_type->has_scalar();
        foreach ($existing_var_type->get_atomic_types() as $type) {
            if ($type instanceof T_Template_Param) {
                if (!$is_equality && !$type->as->is_mixed()) {
                    $template_did_fail = 0;
                    $type = $type->replace_as(self::reconcile_string($assertion, $type->as, null, false, null, $suppressed_issues, $template_did_fail, $is_equality));
                    $redundant = false;
                    if (!$template_did_fail) {
                        $non_string_types[] = $type;
                    }
                } else {
                    $redundant = false;
                    $non_string_types[] = $type;
                }
            } elseif ($type instanceof T_Array_Key) {
                $non_string_types[] = new T_Int();
                $redundant = false;
            } elseif ($type instanceof T_Callable) {
                $non_string_types[] = new T_Callable_Keyed_Array([new Union([new T_Class_String(), new T_Object()]), Type::get_non_empty_string()]);
                $non_string_types[] = new T_Callable_Object();
                $redundant = false;
            } elseif ($type instanceof T_Numeric) {
                $non_string_types[] = $type;
                $redundant = false;
            } elseif ($type instanceof T_Scalar) {
                $redundant = false;
                $non_string_types[] = new T_Float();
                $non_string_types[] = new T_Int();
                $non_string_types[] = new T_Bool();
            } elseif (!$type instanceof T_String) {
                $non_string_types[] = $type;
            } else {
                $redundant = false;
                if ($is_equality) {
                    $non_string_types[] = $type;
                }
            }
        }
        if (!$non_string_types || $redundant) {
            if ($key && $code_location) {
                self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $key, $assertion, $redundant, $negated, $code_location, $suppressed_issues);
            }
            if ($redundant) {
                $failed_reconciliation = Reconciler::RECONCILIATION_REDUNDANT;
            }
        }
        if ($non_string_types) {
            return new Union($non_string_types, ['ignore_falsable_issues' => $existing_var_type->ignore_falsable_issues, 'ignore_nullable_issues' => $existing_var_type->ignore_nullable_issues, 'from_docblock' => $existing_var_type->from_docblock]);
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
        $non_array_types = [];
        $redundant = !$existing_var_type->has_scalar();
        foreach ($existing_var_type->get_atomic_types() as $type) {
            if ($type instanceof T_Template_Param) {
                if (!$is_equality && !$type->as->is_mixed()) {
                    $template_did_fail = 0;
                    $type = $type->replace_as(self::reconcile_array($assertion, $type->as, null, false, null, $suppressed_issues, $template_did_fail, $is_equality));
                    $redundant = false;
                    if (!$template_did_fail) {
                        $non_array_types[] = $type;
                    }
                } else {
                    $redundant = false;
                    $non_array_types[] = $type;
                }
            } elseif ($type instanceof T_Callable) {
                $non_array_types[] = new T_Callable_String();
                $non_array_types[] = new T_Callable_Object($type->from_docblock, $type);
                $redundant = false;
            } elseif ($type instanceof T_Iterable) {
                if (!$type->type_params[0]->is_mixed() || !$type->type_params[1]->is_mixed()) {
                    $non_array_types[] = new T_Generic_Object('Traversable', $type->type_params);
                } else {
                    $non_array_types[] = new T_Named_Object('Traversable');
                }
                $redundant = false;
            } elseif (!$type instanceof T_Array && !$type instanceof T_Keyed_Array) {
                $non_array_types[] = $type;
            } else {
                $redundant = false;
                if ($is_equality) {
                    $non_array_types[] = $type;
                }
            }
        }
        if (!$non_array_types || $redundant) {
            if ($key && $code_location) {
                self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $key, $assertion, $redundant, $negated, $code_location, $suppressed_issues);
            }
            if ($redundant) {
                $failed_reconciliation = Reconciler::RECONCILIATION_REDUNDANT;
            }
        }
        if ($non_array_types) {
            return new Union($non_array_types, ['ignore_falsable_issues' => $existing_var_type->ignore_falsable_issues, 'ignore_nullable_issues' => $existing_var_type->ignore_nullable_issues, 'from_docblock' => $existing_var_type->from_docblock]);
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
        $types = $existing_var_type->get_atomic_types();
        $old_var_type_string = $existing_var_type->get_id();
        $redundant = true;
        if (isset($types['resource'])) {
            $redundant = false;
            unset($types['resource']);
        }
        foreach ($types as &$type) {
            if ($type instanceof T_Template_Param) {
                $new = $type->replace_as(self::reconcile_resource($assertion, $type->as, null, false, null, $suppressed_issues, $failed_reconciliation, $is_equality));
                $redundant = $new === $type;
                $type = $new;
            }
        }
        unset($type);
        if ($redundant || !$types) {
            if ($key && $code_location && !$is_equality) {
                self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $key, $assertion, $redundant, $negated, $code_location, $suppressed_issues);
            }
            if ($redundant) {
                $failed_reconciliation = Reconciler::RECONCILIATION_REDUNDANT;
            }
        }
        if ($types) {
            return $existing_var_type->set_types($types);
        }
        $failed_reconciliation = Reconciler::RECONCILIATION_EMPTY;
        return Type::get_never();
    }
    /**
     * @param string[] $suppressed_issues
     */
    private static function reconcile_is_less_than_or_equal_to(Is_Less_Than_Or_Equal_To $assertion, Union $existing_var_type, bool $inside_loop, string $old_var_type_string, ?string $var_id, bool $negated, ?Code_Location $code_location, array $suppressed_issues): Union
    {
        $existing_var_type = $existing_var_type->get_builder();
        $assertion_value = $assertion->value;
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
                    if ($atomic_type->max_bound === null) {
                        $max_bound = $assertion_value;
                    } else {
                        $max_bound = T_Int_Range::get_new_lowest_bound($assertion_value, $atomic_type->max_bound);
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
                /*elseif ($inside_loop) {
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
     * @param string[] $suppressed_issues
     */
    private static function reconcile_is_greater_than_or_equal_to(Is_Greater_Than_Or_Equal_To $assertion, Union $existing_var_type, bool $inside_loop, string $old_var_type_string, ?string $var_id, bool $negated, ?Code_Location $code_location, array $suppressed_issues): Union
    {
        $existing_var_type = $existing_var_type->get_builder();
        $assertion_value = $assertion->value;
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
                        $min_bound = max($min_bound, $assertion_value);
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
}