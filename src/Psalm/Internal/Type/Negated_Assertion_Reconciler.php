<?php

declare (strict_types=1);
namespace Psalm\Internal\Type;

use Psalm\Code_Location;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Analyzer\Trait_Analyzer;
use Psalm\Internal\Type\Comparator\Atomic_Type_Comparator;
use Psalm\Internal\Type\Comparator\Union_Type_Comparator;
use Psalm\Storage\Assertion;
use Psalm\Storage\Assertion\Is_Class_Not_Equal;
use Psalm\Storage\Assertion\Is_Not_Countable;
use Psalm\Storage\Assertion\Is_Not_Identical;
use Psalm\Storage\Assertion\Is_Not_Type;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Class_String;
use Psalm\Type\Atomic\T_Enum_Case;
use Psalm\Type\Atomic\T_Float;
use Psalm\Type\Atomic\T_Int;
use Psalm\Type\Atomic\T_Iterable;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Atomic\T_Literal_Float;
use Psalm\Type\Atomic\T_Literal_Int;
use Psalm\Type\Atomic\T_Literal_String;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Non_Empty_Nonspecific_Literal_String;
use Psalm\Type\Atomic\T_Non_Empty_String;
use Psalm\Type\Atomic\T_String;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Reconciler;
use Psalm\Type\Union;
use function array_merge;
use function array_values;
use function count;
use function strtolower;
/**
 * @internal
 */
final class Negated_Assertion_Reconciler extends Reconciler
{
    /**
     * @param  string[]   $suppressed_issues
     * @param  Reconciler::RECONCILIATION_*      $failed_reconciliation
     */
    public static function reconcile(Statements_Analyzer $statements_analyzer, Assertion $assertion, Union $existing_var_type, string $old_var_type_string, ?string $key, bool $negated, ?Code_Location $code_location, array $suppressed_issues, int &$failed_reconciliation, bool $inside_loop): Union
    {
        $existing_var_type = Closed_Inheritance_To_Union::map($existing_var_type, $statements_analyzer->get_codebase());
        $is_equality = $assertion->has_equality();
        $assertion_type = $assertion->get_atomic_type();
        // this is a specific value comparison type that cannot be negated
        if ($is_equality && ($assertion_type instanceof T_Literal_Float || $assertion_type instanceof T_Literal_Int || $assertion_type instanceof T_Literal_String || $assertion_type instanceof T_Enum_Case)) {
            if ($existing_var_type->has_mixed()) {
                return $existing_var_type;
            }
            return self::handle_literal_negated_equality($statements_analyzer, $assertion, $assertion_type, $existing_var_type, $old_var_type_string, $key, $negated, $code_location, $suppressed_issues);
        }
        $existing_var_atomic_types = $existing_var_type->get_atomic_types();
        $existing_var_type = $existing_var_type->get_builder();
        $simple_negated_type = Simple_Negated_Assertion_Reconciler::reconcile($statements_analyzer->get_codebase(), $assertion, $existing_var_type->freeze(), $key, $negated, $code_location, $suppressed_issues, $failed_reconciliation, $is_equality, $inside_loop);
        if ($simple_negated_type) {
            return $simple_negated_type;
        }
        $assertion_type = $assertion->get_atomic_type();
        if ($assertion instanceof Is_Not_Type && $assertion_type instanceof T_Iterable && $assertion_type->type_params[1]->is_mixed() || $assertion instanceof Is_Not_Countable) {
            $existing_var_type->remove_type('array');
        }
        if ($assertion instanceof Is_Not_Type && $assertion_type instanceof T_Class_String) {
            $existing_var_type->remove_type(T_Class_String::class);
            $existing_var_type->add_type(new T_String());
        }
        if (!$is_equality && isset($existing_var_atomic_types['int']) && $existing_var_type->from_calculation && ($assertion_type instanceof T_Int || $assertion_type instanceof T_Float)) {
            $existing_var_type->remove_type($assertion_type->get_key());
            if ($assertion_type instanceof T_Int) {
                $existing_var_type->add_type(new T_Float());
            } else {
                $existing_var_type->add_type(new T_Int());
            }
            $existing_var_type->from_calculation = false;
            return $existing_var_type->freeze();
        }
        if (!$is_equality && $assertion_type instanceof T_Named_Object && ($assertion_type->value === 'DateTime' || $assertion_type->value === 'DateTimeImmutable') && isset($existing_var_atomic_types['DateTimeInterface'])) {
            $existing_var_type->remove_type('DateTimeInterface');
            if ($assertion_type->value === 'DateTime') {
                $existing_var_type->add_type(new T_Named_Object('DateTimeImmutable'));
            } else {
                $existing_var_type->add_type(new T_Named_Object('DateTime'));
            }
            return $existing_var_type->freeze();
        }
        if (!$is_equality && $assertion_type instanceof T_Named_Object) {
            foreach ($existing_var_type->get_atomic_types() as $key => $type) {
                if ($type instanceof T_Enum_Case && $type->value === $assertion_type->value) {
                    $existing_var_type->remove_type($key);
                }
            }
        }
        $codebase = $statements_analyzer->get_codebase();
        if ($assertion_type instanceof T_Named_Object && strtolower($assertion_type->value) === 'traversable' && isset($existing_var_atomic_types['iterable'])) {
            /** @var TIterable */
            $iterable = $existing_var_atomic_types['iterable'];
            $existing_var_type->remove_type('iterable');
            $existing_var_type->add_type(new T_Array([$iterable->type_params[0]->has_mixed() ? Type::get_array_key() : $iterable->type_params[0], $iterable->type_params[1]]));
        } elseif ($assertion_type !== null && $assertion_type::class === T_Int::class && isset($existing_var_type->get_atomic_types()['array-key']) && !$is_equality) {
            $existing_var_type->remove_type('array-key');
            $existing_var_type->add_type(new T_String());
        } elseif ($assertion_type instanceof T_Non_Empty_String && $existing_var_type->has_string()) {
            // do nothing
        } elseif ($assertion_type instanceof T_Non_Empty_Nonspecific_Literal_String && $existing_var_type->has_string()) {
            // do nothing
        } elseif ($assertion instanceof Is_Class_Not_Equal) {
            // do nothing
        } elseif ($assertion_type instanceof T_Class_String && $assertion_type->is_loaded) {
            // do nothing
        } elseif ($existing_var_type->is_single() && $existing_var_type->has_named_object_type() && $assertion_type instanceof T_Named_Object && isset($existing_var_type->get_atomic_types()[$assertion_type->get_key()])) {
            // checking if two types share a common parent is not enough to guarantee children are instanceof each other
            // fall through
        } elseif ($existing_var_type->is_array() && ($assertion->get_atomic_type() instanceof T_Array || $assertion->get_atomic_type() instanceof T_Keyed_Array)) {
            //if both types are arrays, try to combine them
            $combined_type = Type_Combiner::combine(array_merge(array_values($existing_var_type->get_atomic_types()), [$assertion->get_atomic_type()]), $codebase);
            $existing_var_type->remove_type('array');
            if ($combined_type->is_single()) {
                $existing_var_type->add_type($combined_type->get_single_atomic());
            }
        } elseif (!$is_equality) {
            $assertion_type = $assertion->get_atomic_type();
            // if there wasn't a direct hit, go deeper, eliminating subtypes
            if ($assertion_type && !$existing_var_type->remove_type($assertion_type->get_key())) {
                if ($assertion_type instanceof T_Named_Object) {
                    foreach ($existing_var_type->get_atomic_types() as $part_name => $existing_var_type_part) {
                        if (!$existing_var_type_part->is_object_type()) {
                            continue;
                        }
                        if (!$existing_var_type_part instanceof T_Template_Param && Atomic_Type_Comparator::is_contained_by($codebase, $existing_var_type_part, $assertion_type, false, false)) {
                            $existing_var_type->remove_type($part_name);
                        } elseif (Atomic_Type_Comparator::is_contained_by($codebase, $assertion_type, $existing_var_type_part, false, false)) {
                            $existing_var_type->different = true;
                        }
                    }
                }
            }
        }
        $existing_var_type = $existing_var_type->freeze();
        if ($assertion instanceof Is_Not_Identical && ($key !== '$this' || !$statements_analyzer->get_source()->get_source() instanceof Trait_Analyzer)) {
            $assertion_type = new Union([$assertion->type]);
            if ($key && $code_location && !Union_Type_Comparator::can_expression_types_be_identical($statements_analyzer->get_codebase(), $existing_var_type, $assertion_type)) {
                self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $key, $assertion, true, $negated, $code_location, $suppressed_issues);
            }
        }
        if ($existing_var_type->is_union_empty()) {
            if ($key !== '$this' || !$statements_analyzer->get_source()->get_source() instanceof Trait_Analyzer) {
                if ($key && $code_location && !$is_equality) {
                    self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $key, $assertion, false, $negated, $code_location, $suppressed_issues);
                }
            }
            $failed_reconciliation = Reconciler::RECONCILIATION_EMPTY;
            return Type::get_never();
        }
        return $existing_var_type;
    }
    /**
     * @param  TLiteralInt|TLiteralString|TLiteralFloat|TEnumCase $assertion_type
     * @param  string[]   $suppressed_issues
     */
    private static function handle_literal_negated_equality(Statements_Analyzer $statements_analyzer, Assertion $assertion, Atomic $assertion_type, Union $existing_var_type, string $old_var_type_string, ?string $key, bool $negated, ?Code_Location $code_location, array $suppressed_issues): Union
    {
        $existing_var_type = $existing_var_type->get_builder();
        $existing_var_atomic_types = $existing_var_type->get_atomic_types();
        $redundant = true;
        $did_match_literal_type = false;
        $scalar_var_type = null;
        if ($assertion_type instanceof T_Literal_Int) {
            if ($existing_var_type->has_int()) {
                if ($existing_var_type->get_literal_ints()) {
                    $did_match_literal_type = true;
                    if ($existing_var_type->remove_type($assertion_type->get_key())) {
                        $redundant = false;
                    }
                }
                $existing_range_types = $existing_var_type->get_range_ints();
                foreach ($existing_range_types as $int_key => $literal_type) {
                    if ($literal_type->contains($assertion_type->value)) {
                        $redundant = false;
                        $existing_var_type->remove_type($int_key);
                        if ($literal_type->min_bound === null || $literal_type->min_bound <= $assertion_type->value - 1) {
                            $existing_var_type->add_type(new Type\Atomic\T_Int_Range($literal_type->min_bound, $assertion_type->value - 1));
                        }
                        if ($literal_type->max_bound === null || $literal_type->max_bound >= $assertion_type->value + 1) {
                            $existing_var_type->add_type(new Type\Atomic\T_Int_Range($assertion_type->value + 1, $literal_type->max_bound));
                        }
                    }
                }
                if (isset($existing_var_type->get_atomic_types()['int']) && $existing_var_type->get_atomic_types()['int']::class === Type\Atomic\T_Int::class) {
                    $redundant = false;
                    //this may be used to generate a range containing any int except the one that was asserted against
                    //but this is failing some tests
                    /*$existing_var_type->removeType('int');
                      $existing_var_type->addType(new Type\Atomic\TIntRange(null, $assertion_type->value - 1));
                      $existing_var_type->addType(new Type\Atomic\TIntRange($assertion_type->value + 1, null));*/
                }
            } else {
                $scalar_var_type = $assertion_type;
            }
        } elseif ($assertion_type instanceof T_Literal_String) {
            if ($existing_var_type->has_string()) {
                if ($existing_var_type->get_literal_strings()) {
                    $did_match_literal_type = true;
                    if ($existing_var_type->remove_type($assertion_type->get_key())) {
                        $redundant = false;
                    }
                } elseif ($assertion_type->value === "") {
                    $existing_var_type->add_type(new T_Non_Empty_String());
                }
            } elseif ($assertion_type::class === T_Literal_String::class) {
                $scalar_var_type = $assertion_type;
            }
        } elseif ($assertion_type instanceof T_Literal_Float) {
            if ($existing_var_type->has_float()) {
                if ($existing_var_type->get_literal_floats()) {
                    $did_match_literal_type = true;
                    if ($existing_var_type->remove_type($assertion_type->get_key())) {
                        $redundant = false;
                    }
                }
            } else {
                $scalar_var_type = $assertion_type;
            }
        } else {
            $fq_enum_name = $assertion_type->value;
            $case_name = $assertion_type->case_name;
            foreach ($existing_var_type->get_atomic_types() as $atomic_key => $atomic_type) {
                if ($atomic_type::class === T_Named_Object::class && $atomic_type->value === $fq_enum_name) {
                    $codebase = $statements_analyzer->get_codebase();
                    $enum_storage = $codebase->classlike_storage_provider->get($fq_enum_name);
                    if (!$enum_storage->is_enum || !$enum_storage->enum_cases) {
                        $scalar_var_type = $assertion_type;
                    } else {
                        $existing_var_type->remove_type($atomic_type->get_key());
                        $redundant = false;
                        foreach ($enum_storage->enum_cases as $alt_case_name => $_) {
                            if ($alt_case_name === $case_name) {
                                continue;
                            }
                            $existing_var_type->add_type(new T_Enum_Case($fq_enum_name, $alt_case_name));
                        }
                    }
                } elseif ($atomic_type instanceof T_Enum_Case && $atomic_type->value === $fq_enum_name && $atomic_type->case_name !== $case_name) {
                    $did_match_literal_type = true;
                } elseif ($atomic_key === $assertion_type->get_key()) {
                    $existing_var_type->remove_type($assertion_type->get_key());
                    $redundant = false;
                }
            }
        }
        $existing_var_type = $existing_var_type->freeze();
        if ($key && $code_location) {
            if ($did_match_literal_type && ($redundant || count($existing_var_atomic_types) === 1)) {
                self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $key, $assertion, $redundant, $negated, $code_location, $suppressed_issues);
            } elseif ($scalar_var_type && $assertion instanceof Is_Not_Identical && ($key !== '$this' || !$statements_analyzer->get_source()->get_source() instanceof Trait_Analyzer)) {
                if (!Union_Type_Comparator::can_expression_types_be_identical($statements_analyzer->get_codebase(), $existing_var_type, new Union([$scalar_var_type]))) {
                    self::trigger_issue_for_impossible($existing_var_type, $old_var_type_string, $key, $assertion, true, $negated, $code_location, $suppressed_issues);
                }
            }
        }
        return $existing_var_type;
    }
}