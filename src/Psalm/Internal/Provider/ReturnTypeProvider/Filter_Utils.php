<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider\Return_Type_Provider;

use Php_Parser\Node\Arg;
use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Data_Flow\Data_Flow_Node;
use Psalm\Internal\Type\Comparator\Callable_Type_Comparator;
use Psalm\Internal\Type\Comparator\Union_Type_Comparator;
use Psalm\Internal\Type\Type_Combiner;
use Psalm\Issue\Invalid_Argument;
use Psalm\Issue\Redundant_Flag;
use Psalm\Issue_Buffer;
use Psalm\Type;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Array_Key;
use Psalm\Type\Atomic\T_Bool;
use Psalm\Type\Atomic\T_False;
use Psalm\Type\Atomic\T_Float;
use Psalm\Type\Atomic\T_Int;
use Psalm\Type\Atomic\T_Int_Range;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Atomic\T_Literal_Float;
use Psalm\Type\Atomic\T_Literal_Int;
use Psalm\Type\Atomic\T_Literal_String;
use Psalm\Type\Atomic\T_Mixed;
use Psalm\Type\Atomic\T_Non_Empty_Array;
use Psalm\Type\Atomic\T_Non_Empty_Nonspecific_Literal_String;
use Psalm\Type\Atomic\T_Non_Empty_String;
use Psalm\Type\Atomic\T_Non_Falsy_String;
use Psalm\Type\Atomic\T_Null;
use Psalm\Type\Atomic\T_Numeric;
use Psalm\Type\Atomic\T_Numeric_String;
use Psalm\Type\Atomic\T_Scalar;
use Psalm\Type\Atomic\T_String;
use Psalm\Type\Atomic\T_True;
use Psalm\Type\Union;
use UnexpectedValueException;
use function array_diff;
use function array_keys;
use function array_merge;
use function filter_var;
use function get_class;
use function implode;
use function in_array;
use function preg_match;
use function strtolower;
use const FILTER_CALLBACK;
use const FILTER_DEFAULT;
use const FILTER_FLAG_ALLOW_FRACTION;
use const FILTER_FLAG_ALLOW_HEX;
use const FILTER_FLAG_ALLOW_OCTAL;
use const FILTER_FLAG_ALLOW_SCIENTIFIC;
use const FILTER_FLAG_ALLOW_THOUSAND;
use const FILTER_FLAG_EMAIL_UNICODE;
use const FILTER_FLAG_ENCODE_AMP;
use const FILTER_FLAG_ENCODE_HIGH;
use const FILTER_FLAG_ENCODE_LOW;
use const FILTER_FLAG_HOSTNAME;
use const FILTER_FLAG_IPV4;
use const FILTER_FLAG_IPV6;
use const FILTER_FLAG_NONE;
use const FILTER_FLAG_NO_ENCODE_QUOTES;
use const FILTER_FLAG_NO_PRIV_RANGE;
use const FILTER_FLAG_NO_RES_RANGE;
use const FILTER_FLAG_PATH_REQUIRED;
use const FILTER_FLAG_QUERY_REQUIRED;
use const FILTER_FLAG_STRIP_BACKTICK;
use const FILTER_FLAG_STRIP_HIGH;
use const FILTER_FLAG_STRIP_LOW;
use const FILTER_FORCE_ARRAY;
use const FILTER_NULL_ON_FAILURE;
use const FILTER_REQUIRE_ARRAY;
use const FILTER_REQUIRE_SCALAR;
use const FILTER_SANITIZE_ADD_SLASHES;
use const FILTER_SANITIZE_EMAIL;
use const FILTER_SANITIZE_ENCODED;
use const FILTER_SANITIZE_FULL_SPECIAL_CHARS;
use const FILTER_SANITIZE_NUMBER_FLOAT;
use const FILTER_SANITIZE_NUMBER_INT;
use const FILTER_SANITIZE_SPECIAL_CHARS;
use const FILTER_SANITIZE_URL;
use const FILTER_UNSAFE_RAW;
use const FILTER_VALIDATE_BOOLEAN;
use const FILTER_VALIDATE_DOMAIN;
use const FILTER_VALIDATE_EMAIL;
use const FILTER_VALIDATE_FLOAT;
use const FILTER_VALIDATE_INT;
use const FILTER_VALIDATE_IP;
use const FILTER_VALIDATE_MAC;
use const FILTER_VALIDATE_REGEXP;
use const FILTER_VALIDATE_URL;
/**
 * @internal
 */
final class Filter_Utils
{
    public static function missing_first_arg(Codebase $codebase): Union
    {
        if ($codebase->analysis_php_version_id >= 80000) {
            // throws
            return Type::get_never();
        }
        return Type::get_null();
    }
    public static function get_filter_arg_value_or_error(Arg $filter_arg, Statements_Analyzer $statements_analyzer, Codebase $codebase): int|Union|null
    {
        $filter_arg_type = $statements_analyzer->node_data->get_type($filter_arg->value);
        if (!$filter_arg_type) {
            return null;
        }
        if (!$filter_arg_type->is_int()) {
            // invalid
            if ($codebase->analysis_php_version_id >= 80000) {
                // throws
                return Type::get_never();
            }
            // will return null independent of FILTER_NULL_ON_FAILURE or default option
            return Type::get_null();
        }
        if (!$filter_arg_type->is_single_int_literal()) {
            // too complex for now
            return null;
        }
        $all_filters = self::get_filters($codebase);
        $filter_int_used = $filter_arg_type->get_single_int_literal()->value;
        if (!isset($all_filters[$filter_int_used])) {
            // inconsistently, this will always return false, even when FILTER_NULL_ON_FAILURE
            // or a default option is set
            // and will also not use any default set
            return Type::get_false();
        }
        return $filter_int_used;
    }
    /** @return array{flags_int_used: int, options: TKeyedArray|null}|Union|null */
    public static function get_options_arg_value_or_error(Arg $options_arg, Statements_Analyzer $statements_analyzer, Codebase $codebase, Code_Location $code_location, string $function_id, int $filter_int_used): array|Union|null
    {
        $options_arg_type = $statements_analyzer->node_data->get_type($options_arg->value);
        if (!$options_arg_type) {
            return null;
        }
        if ($options_arg_type->is_array()) {
            $return_null = false;
            $defaults = ['flags_int_used' => FILTER_FLAG_NONE, 'options' => null];
            $atomic_type = $options_arg_type->get_array();
            if ($atomic_type instanceof T_Keyed_Array) {
                $redundant_keys = array_diff(array_keys($atomic_type->properties), ['flags', 'options']);
                if ($redundant_keys !== []) {
                    // reported as it's usually an oversight/misunderstanding of how the function works
                    // it's silently ignored by the function though
                    Issue_Buffer::maybe_add(new Redundant_Flag('The options array contains unused keys ' . implode(', ', $redundant_keys), $code_location), $statements_analyzer->get_suppressed_issues());
                }
                if (isset($atomic_type->properties['options'])) {
                    if ($filter_int_used === FILTER_CALLBACK) {
                        $only_callables = true;
                        foreach ($atomic_type->properties['options']->get_atomic_types() as $option_atomic) {
                            if ($option_atomic->is_callable_type()) {
                                continue;
                            }
                            if (Callable_Type_Comparator::get_callable_from_atomic($codebase, $option_atomic, null, $statements_analyzer)) {
                                continue;
                            }
                            $only_callables = false;
                        }
                        if ($atomic_type->properties['options']->possibly_undefined) {
                            $only_callables = false;
                        }
                        if (!$only_callables) {
                            return self::missing_filter_callback_callable($function_id, $code_location, $statements_analyzer, $codebase);
                        }
                        // eventually can improve it to return the type from the callback
                        // there are no flags or other options/flags, so it can be handled here directly
                        // @todo
                        $return_type = Type::get_mixed();
                        return self::add_return_taint($statements_analyzer, $code_location, $return_type, $function_id);
                    }
                    if (!$atomic_type->properties['options']->is_array()) {
                        // silently ignored by the function, but this usually indicates a bug
                        Issue_Buffer::maybe_add(new Invalid_Argument('The "options" key in ' . $function_id . ' must be an array', $code_location, $function_id), $statements_analyzer->get_suppressed_issues());
                    } elseif (($options_array = $atomic_type->properties['options']->get_array()) && $options_array instanceof T_Keyed_Array) {
                        $defaults['options'] = $options_array;
                    } else {
                        // cannot infer a 100% correct specific return type
                        $return_null = true;
                    }
                }
                if (isset($atomic_type->properties['flags'])) {
                    if ($atomic_type->properties['flags']->is_single_int_literal()) {
                        $defaults['flags_int_used'] = $atomic_type->properties['flags']->get_single_int_literal()->value;
                    } elseif ($atomic_type->properties['flags']->is_int()) {
                        // cannot infer a 100% correct specific return type
                        $return_null = true;
                    } else {
                        // silently ignored by the function, but this usually indicates a bug
                        Issue_Buffer::maybe_add(new Invalid_Argument('The "flags" key in ' . $function_id . ' must be a valid flag', $code_location, $function_id), $statements_analyzer->get_suppressed_issues());
                        $defaults['flags_int_used'] = FILTER_FLAG_NONE;
                    }
                }
                return $return_null ? null : $defaults;
            }
            // cannot infer a 100% correct specific return type
            return null;
        }
        if ($filter_int_used === FILTER_CALLBACK) {
            return self::missing_filter_callback_callable($function_id, $code_location, $statements_analyzer, $codebase);
        }
        if ($options_arg_type->is_single_int_literal()) {
            return ['flags_int_used' => $options_arg_type->get_single_int_literal()->value, 'options' => null];
        }
        if ($options_arg_type->is_int()) {
            // in most cases we cannot infer a 100% correct specific return type though
            // unless all int are literal
            // @todo could handle all literal int cases
            return null;
        }
        foreach ($options_arg_type->get_atomic_types() as $atomic_type) {
            if ($atomic_type instanceof T_Array) {
                continue;
            }
            if ($atomic_type instanceof T_Int) {
                continue;
            }
            if ($atomic_type instanceof T_Float) {
                // ignored
                continue;
            }
            if ($atomic_type instanceof T_Bool) {
                // ignored
                continue;
            }
            if ($codebase->analysis_php_version_id >= 80000) {
                // throws for the invalid type
                // for the other types it will still work correctly
                // however "never" is a bottom type
                // and will be lost, therefore it's better to return it here
                // to identify hard to find bugs in the code
                return Type::get_never();
            }
            // before PHP 8, it's ignored but gives a PHP notice
        }
        // array|int type which is too complex for now
        // or any other invalid type
        return null;
    }
    public static function missing_filter_callback_callable(string $function_id, Code_Location $code_location, Statements_Analyzer $statements_analyzer, Codebase $codebase): Union
    {
        Issue_Buffer::maybe_add(new Invalid_Argument('The "options" key in ' . $function_id . ' must be a callable for FILTER_CALLBACK', $code_location, $function_id), $statements_analyzer->get_suppressed_issues());
        if ($codebase->analysis_php_version_id >= 80000) {
            // throws
            return Type::get_never();
        }
        // flags are ignored here
        return Type::get_null();
    }
    /** @return array{Union, Union, Union} */
    public static function get_fails_not_set_type(int $flags_int_used): array
    {
        $fails_type = Type::get_false();
        $not_set_type = Type::get_null();
        if (self::has_flag($flags_int_used, FILTER_NULL_ON_FAILURE)) {
            $fails_type = Type::get_null();
            $not_set_type = Type::get_false();
        }
        $fails_or_not_set_type = new Union([new T_Null(), new T_False()]);
        return [$fails_type, $not_set_type, $fails_or_not_set_type];
    }
    public static function has_flag(int $flags, int $flag): bool
    {
        if ($flags === 0) {
            return false;
        }
        if (($flags & $flag) === $flag) {
            return true;
        }
        return false;
    }
    public static function check_redundant_flags(int $filter_int_used, int $flags_int_used, Union $fails_type, Statements_Analyzer $statements_analyzer, Code_Location $code_location, Codebase $codebase): ?Union
    {
        $all_filters = self::get_filters($codebase);
        $flags_int_used_rest = $flags_int_used;
        foreach ($all_filters[$filter_int_used]['flags'] as $flag) {
            if ($flags_int_used_rest === 0) {
                break;
            }
            if (self::has_flag($flags_int_used_rest, $flag)) {
                $flags_int_used_rest = $flags_int_used_rest ^ $flag;
            }
        }
        if ($flags_int_used_rest !== 0) {
            // invalid flags used
            // while they are silently ignored
            // usually it means there's a mistake and the filter doesn't actually do what one expects
            // as otherwise the flag wouldn't have been provided
            Issue_Buffer::maybe_add(new Redundant_Flag('Not all flags used are supported by the filter used', $code_location), $statements_analyzer->get_suppressed_issues());
        }
        if (self::has_flag($flags_int_used, FILTER_REQUIRE_ARRAY) && self::has_flag($flags_int_used, FILTER_FORCE_ARRAY)) {
            Issue_Buffer::maybe_add(new Redundant_Flag('Flag FILTER_FORCE_ARRAY is ignored when using FILTER_REQUIRE_ARRAY', $code_location), $statements_analyzer->get_suppressed_issues());
        }
        if ($filter_int_used === FILTER_VALIDATE_REGEXP && (self::has_flag($flags_int_used, FILTER_REQUIRE_ARRAY) || self::has_flag($flags_int_used, FILTER_FORCE_ARRAY) || self::has_flag($flags_int_used, FILTER_REQUIRE_SCALAR))) {
            Issue_Buffer::maybe_add(new Redundant_Flag('FILTER_VALIDATE_REGEXP will ignore ' . 'FILTER_REQUIRE_ARRAY/FILTER_FORCE_ARRAY/FILTER_REQUIRE_SCALAR ' . 'as it only works on scalar types', $code_location), $statements_analyzer->get_suppressed_issues());
        }
        if (self::has_flag($flags_int_used, FILTER_FLAG_STRIP_LOW) && self::has_flag($flags_int_used, FILTER_FLAG_ENCODE_LOW)) {
            Issue_Buffer::maybe_add(new Redundant_Flag('Using flag FILTER_FLAG_ENCODE_LOW is redundant when using FILTER_FLAG_STRIP_LOW', $code_location), $statements_analyzer->get_suppressed_issues());
        }
        if (self::has_flag($flags_int_used, FILTER_FLAG_STRIP_HIGH) && self::has_flag($flags_int_used, FILTER_FLAG_ENCODE_HIGH)) {
            Issue_Buffer::maybe_add(new Redundant_Flag('Using flag FILTER_FLAG_ENCODE_HIGH is redundant when using FILTER_FLAG_STRIP_HIGH', $code_location), $statements_analyzer->get_suppressed_issues());
        }
        if (self::has_flag($flags_int_used, FILTER_REQUIRE_ARRAY) && self::has_flag($flags_int_used, FILTER_REQUIRE_SCALAR)) {
            Issue_Buffer::maybe_add(new Redundant_Flag('You cannot use FILTER_REQUIRE_ARRAY together with FILTER_REQUIRE_SCALAR flag', $code_location), $statements_analyzer->get_suppressed_issues());
            // FILTER_REQUIRE_ARRAY will make PHP ignore FILTER_FORCE_ARRAY
            return $fails_type;
        }
        return null;
    }
    /** @return array{Union|null, float|int|null, float|int|null, bool, non-falsy-string|true|null} */
    public static function get_options(int $filter_int_used, int $flags_int_used, ?T_Keyed_Array $options, Statements_Analyzer $statements_analyzer, Code_Location $code_location, Codebase $codebase, string $function_id): array
    {
        $default = null;
        $min_range = null;
        $max_range = null;
        $has_range = false;
        $regexp = null;
        if (!$options) {
            return [$default, $min_range, $max_range, $has_range, $regexp];
        }
        $all_filters = self::get_filters($codebase);
        foreach ($options->properties as $option => $option_value) {
            if (!isset($all_filters[$filter_int_used]['options'][$option])) {
                Issue_Buffer::maybe_add(new Redundant_Flag('The option ' . $option . ' is not valid for the filter used', $code_location), $statements_analyzer->get_suppressed_issues());
                continue;
            }
            if (!Union_Type_Comparator::is_contained_by($codebase, $option_value, $all_filters[$filter_int_used]['options'][$option])) {
                // silently ignored by the function, but it's a bug in the code
                // since the filtering/option will not do what you expect
                Issue_Buffer::maybe_add(new Invalid_Argument('The option "' . $option . '" of ' . $function_id . ' expects ' . $all_filters[$filter_int_used]['options'][$option]->get_id() . ', but ' . $option_value->get_id() . ' provided', $code_location, $function_id), $statements_analyzer->get_suppressed_issues());
                continue;
            }
            if ($option === 'default') {
                $default = $option_value;
                if (self::has_flag($flags_int_used, FILTER_NULL_ON_FAILURE)) {
                    Issue_Buffer::maybe_add(new Redundant_Flag('Redundant flag FILTER_NULL_ON_FAILURE when using the "default" option', $code_location), $statements_analyzer->get_suppressed_issues());
                }
                continue;
            }
            // currently only int ranges are supported
            // must be numeric, otherwise we would have continued above already
            if ($option === 'min_range' && $option_value->is_single_literal()) {
                if ($filter_int_used === FILTER_VALIDATE_INT) {
                    $min_range = (int) $option_value->get_single_literal()->value;
                } elseif ($filter_int_used === FILTER_VALIDATE_FLOAT) {
                    $min_range = (float) $option_value->get_single_literal()->value;
                }
            }
            if ($option === 'max_range' && $option_value->is_single_literal()) {
                if ($filter_int_used === FILTER_VALIDATE_INT) {
                    $max_range = (int) $option_value->get_single_literal()->value;
                } elseif ($filter_int_used === FILTER_VALIDATE_FLOAT) {
                    $max_range = (float) $option_value->get_single_literal()->value;
                }
            }
            if (($filter_int_used === FILTER_VALIDATE_INT || $filter_int_used === FILTER_VALIDATE_FLOAT) && ($option === 'min_range' || $option === 'max_range')) {
                $has_range = true;
            }
            if ($filter_int_used === FILTER_VALIDATE_REGEXP && $option === 'regexp') {
                if ($option_value->is_single_string_literal()) {
                    /**
                     * if it's another type, we would have reported an error above already
                     * @var non-falsy-string $regexp
                     */
                    $regexp = $option_value->get_single_string_literal()->value;
                } elseif ($option_value->is_string()) {
                    $regexp = true;
                }
            }
        }
        return [$default, $min_range, $max_range, $has_range, $regexp];
    }
    protected static function is_range_valid(float|int|null $min_range, float|int|null $max_range, Statements_Analyzer $statements_analyzer, Code_Location $code_location, string $function_id): bool
    {
        if ($min_range !== null && $max_range !== null && $min_range > $max_range) {
            Issue_Buffer::maybe_add(new Invalid_Argument('min_range cannot be larger than max_range', $code_location, $function_id), $statements_analyzer->get_suppressed_issues());
            return false;
        }
        return true;
    }
    /**
     * can't split this because the switch is complex since there are too many possibilities
     *
     * @psalm-suppress ComplexMethod
     * @param Union|null $not_set_type null if undefined filtered variable will return $fails_type
     * @param non-falsy-string|true|null $regexp
     */
    public static function get_return_type(int $filter_int_used, int $flags_int_used, Union $input_type, Union $fails_type, ?Union $not_set_type, Statements_Analyzer $statements_analyzer, Code_Location $code_location, Codebase $codebase, string $function_id, bool $has_range, float|int|null $min_range, float|int|null $max_range, string|bool|null $regexp, bool $in_array_recursion = false): Union
    {
        // if we are inside a recursion of e.g. array<never, never>
        // it will never fail or change the type, so we can immediately return
        if ($in_array_recursion && $input_type->is_never()) {
            return $input_type;
        }
        $from_array = [];
        // will only handle arrays correctly if either flag is set, otherwise always error
        // regexp doesn't work on arrays
        if ((self::has_flag($flags_int_used, FILTER_FORCE_ARRAY) || self::has_flag($flags_int_used, FILTER_REQUIRE_ARRAY)) && $filter_int_used !== FILTER_VALIDATE_REGEXP && !self::has_flag($flags_int_used, FILTER_REQUIRE_SCALAR)) {
            foreach ($input_type->get_atomic_types() as $key => $atomic_type) {
                if ($atomic_type instanceof T_Keyed_Array) {
                    $input_type = $input_type->get_builder();
                    $input_type->remove_type($key);
                    $input_type = $input_type->freeze();
                    $new = [];
                    foreach ($atomic_type->properties as $k => $property) {
                        if ($property->is_never()) {
                            $new[$k] = $property;
                            continue;
                        }
                        $new[$k] = self::get_return_type(
                            $filter_int_used,
                            $flags_int_used,
                            $property,
                            $fails_type,
                            // irrelevant in nested elements
                            null,
                            $statements_analyzer,
                            $code_location,
                            $codebase,
                            $function_id,
                            $has_range,
                            $min_range,
                            $max_range,
                            $regexp,
                            true
                        );
                    }
                    // false positive error in psalm when we loop over a non-empty array
                    if ($new === []) {
                        throw new UnexpectedValueException('This is impossible');
                    }
                    $fallback_params = null;
                    if ($atomic_type->fallback_params) {
                        [$keys_union, $values_union] = $atomic_type->fallback_params;
                        $values_union = self::get_return_type(
                            $filter_int_used,
                            $flags_int_used,
                            $values_union,
                            $fails_type,
                            // irrelevant in nested elements
                            null,
                            $statements_analyzer,
                            $code_location,
                            $codebase,
                            $function_id,
                            $has_range,
                            $min_range,
                            $max_range,
                            $regexp,
                            true
                        );
                        $fallback_params = [$keys_union, $values_union];
                    }
                    $from_array[] = new T_Keyed_Array($new, $atomic_type->class_strings, $fallback_params, $atomic_type->is_list);
                    continue;
                }
                if ($atomic_type instanceof T_Array) {
                    $input_type = $input_type->get_builder();
                    $input_type->remove_type($key);
                    $input_type = $input_type->freeze();
                    [$keys_union, $values_union] = $atomic_type->type_params;
                    $values_union = self::get_return_type(
                        $filter_int_used,
                        $flags_int_used,
                        $values_union,
                        $fails_type,
                        // irrelevant in nested elements
                        null,
                        $statements_analyzer,
                        $code_location,
                        $codebase,
                        $function_id,
                        $has_range,
                        $min_range,
                        $max_range,
                        $regexp,
                        true
                    );
                    if ($atomic_type instanceof T_Non_Empty_Array) {
                        $from_array[] = new T_Non_Empty_Array([$keys_union, $values_union]);
                    } else {
                        $from_array[] = new T_Array([$keys_union, $values_union]);
                    }
                    continue;
                }
                // can be an array too
                if ($atomic_type instanceof T_Mixed) {
                    $from_array[] = new T_Array([new Union([new T_Array_Key()]), new Union([new T_Mixed()])]);
                }
            }
        }
        $can_fail = false;
        $filter_types = [];
        switch ($filter_int_used) {
            case FILTER_VALIDATE_FLOAT:
                if (!self::is_range_valid($min_range, $max_range, $statements_analyzer, $code_location, $function_id)) {
                    $can_fail = true;
                    break;
                }
                foreach ($input_type->get_atomic_types() as $atomic_type) {
                    if ($atomic_type instanceof T_Literal_Float) {
                        if ($min_range !== null && $min_range > $atomic_type->value) {
                            $can_fail = true;
                            continue;
                        }
                        if ($max_range !== null && $max_range < $atomic_type->value) {
                            $can_fail = true;
                            continue;
                        }
                        if ($min_range !== null || $max_range !== null || $has_range === false) {
                            $filter_types[] = $atomic_type;
                            continue;
                        }
                        // we don't know what the min/max of the range are
                        // and it might be out of the range too
                        // float ranges aren't supported yet
                        $filter_types[] = new T_Float();
                    } elseif ($atomic_type instanceof T_Float) {
                        if ($has_range === false) {
                            $filter_types[] = $atomic_type;
                            continue;
                        }
                        // float ranges aren't supported yet
                        $filter_types[] = new T_Float();
                    }
                    if ($atomic_type instanceof T_Literal_Int) {
                        if ($min_range !== null && $min_range > $atomic_type->value) {
                            $can_fail = true;
                            continue;
                        }
                        if ($max_range !== null && $max_range < $atomic_type->value) {
                            $can_fail = true;
                            continue;
                        }
                        if ($min_range !== null || $max_range !== null || $has_range === false) {
                            $filter_types[] = new T_Literal_Float((float) $atomic_type->value);
                            continue;
                        }
                        // we don't know what the min/max of the range are
                        // and it might be out of the range too
                        $filter_types[] = new T_Float();
                    } elseif ($atomic_type instanceof T_Int) {
                        $filter_types[] = new T_Float();
                        if ($has_range === false) {
                            continue;
                        }
                    }
                    if ($atomic_type instanceof T_Literal_String) {
                        if (($string_to_float = filter_var($atomic_type->value, FILTER_VALIDATE_FLOAT)) === false) {
                            $can_fail = true;
                            continue;
                        }
                        if ($min_range !== null && $min_range > $string_to_float) {
                            $can_fail = true;
                            continue;
                        }
                        if ($max_range !== null && $max_range < $string_to_float) {
                            $can_fail = true;
                            continue;
                        }
                        if ($min_range !== null || $max_range !== null || $has_range === false) {
                            $filter_types[] = new T_Literal_Float($string_to_float);
                            continue;
                        }
                        // we don't know what the min/max of the range are
                        // and it might be out of the range too
                        $filter_types[] = new T_Float();
                    } elseif ($atomic_type instanceof T_String) {
                        $filter_types[] = new T_Float();
                    }
                    if ($atomic_type instanceof T_Bool) {
                        if ($min_range !== null && $min_range > 1) {
                            $can_fail = true;
                            continue;
                        }
                        if ($max_range !== null && $max_range < 1) {
                            $can_fail = true;
                            continue;
                        }
                        if ($atomic_type instanceof T_False) {
                            $can_fail = true;
                            continue;
                        }
                        if ($min_range !== null || $max_range !== null || $has_range === false) {
                            $filter_types[] = new T_Literal_Float(1.0);
                            if ($atomic_type instanceof T_True) {
                                continue;
                            }
                        }
                        // we don't know what the min/max of the range are
                        // and it might be out of the range too
                        $filter_types[] = new T_Float();
                    }
                    // only these specific classes, not any class that extends either
                    // to avoid matching already better handled cases from above, e.g. float is numeric and scalar
                    if ($atomic_type instanceof T_Mixed || $atomic_type::class === T_Numeric::class || $atomic_type::class === T_Scalar::class) {
                        $filter_types[] = new T_Float();
                    }
                    $can_fail = true;
                }
                break;
            case FILTER_VALIDATE_BOOLEAN:
                foreach ($input_type->get_atomic_types() as $atomic_type) {
                    if ($atomic_type instanceof T_Bool) {
                        $filter_types[] = $atomic_type;
                        continue;
                    }
                    if ($atomic_type instanceof T_Literal_Int && $atomic_type->value === 1 || $atomic_type instanceof T_Literal_Float && $atomic_type->value === 1.0 || $atomic_type instanceof T_Literal_String && in_array(strtolower($atomic_type->value), ['1', 'true', 'on', 'yes'], true)) {
                        $filter_types[] = new T_True();
                        continue;
                    }
                    if (self::has_flag($flags_int_used, FILTER_NULL_ON_FAILURE) && ($atomic_type instanceof T_Literal_Int && $atomic_type->value === 0 || $atomic_type instanceof T_Literal_Float && $atomic_type->value === 0.0 || $atomic_type instanceof T_Literal_String && in_array(strtolower($atomic_type->value), ['0', 'false', 'off', 'no', ''], true))) {
                        $filter_types[] = new T_False();
                        continue;
                    }
                    if ($atomic_type instanceof T_Literal_Int || $atomic_type instanceof T_Literal_Float || $atomic_type instanceof T_Literal_String) {
                        // all other literals will fail
                        $can_fail = true;
                        continue;
                    }
                    if ($atomic_type instanceof T_Mixed || $atomic_type instanceof T_String || $atomic_type instanceof T_Int || $atomic_type instanceof T_Float || $atomic_type instanceof T_Numeric || $atomic_type instanceof T_Scalar) {
                        $filter_types[] = new T_Bool();
                    }
                    $can_fail = true;
                }
                break;
            case FILTER_VALIDATE_INT:
                if (!self::is_range_valid($min_range, $max_range, $statements_analyzer, $code_location, $function_id)) {
                    $can_fail = true;
                    break;
                }
                $min_range = $min_range !== null ? (int) $min_range : null;
                $max_range = $max_range !== null ? (int) $max_range : null;
                if ($min_range !== null || $max_range !== null) {
                    $int_type = new T_Int_Range($min_range, $max_range);
                } else {
                    $int_type = new T_Int();
                }
                foreach ($input_type->get_atomic_types() as $atomic_type) {
                    if ($atomic_type instanceof T_Literal_Int) {
                        if ($min_range !== null && $min_range > $atomic_type->value) {
                            $can_fail = true;
                            continue;
                        }
                        if ($max_range !== null && $max_range < $atomic_type->value) {
                            $can_fail = true;
                            continue;
                        }
                        if ($min_range !== null || $max_range !== null || $has_range === false) {
                            $filter_types[] = $atomic_type;
                            continue;
                        }
                        // we don't know what the min/max of the range are
                        // and it might be out of the range too
                        $filter_types[] = new T_Int();
                    } elseif ($atomic_type instanceof T_Int) {
                        if ($has_range === false) {
                            $filter_types[] = $atomic_type;
                            continue;
                        }
                        $filter_types[] = $int_type;
                    }
                    if ($atomic_type instanceof T_Literal_Float) {
                        if ((float) (int) $atomic_type->value !== $atomic_type->value) {
                            $can_fail = true;
                            continue;
                        }
                        if ($min_range !== null && $min_range > $atomic_type->value) {
                            $can_fail = true;
                            continue;
                        }
                        if ($max_range !== null && $max_range < $atomic_type->value) {
                            $can_fail = true;
                            continue;
                        }
                        if ($min_range !== null || $max_range !== null || $has_range === false) {
                            $filter_types[] = new T_Literal_Int((int) $atomic_type->value);
                            continue;
                        }
                        // we don't know what the min/max of the range are
                        // and it might be out of the range too
                        $filter_types[] = $int_type;
                    } elseif ($atomic_type instanceof T_Float) {
                        $filter_types[] = $int_type;
                    }
                    if ($atomic_type instanceof T_Literal_String) {
                        if (($string_to_int = filter_var($atomic_type->value, FILTER_VALIDATE_INT)) === false) {
                            $can_fail = true;
                            continue;
                        }
                        if ($min_range !== null && $min_range > $string_to_int) {
                            $can_fail = true;
                            continue;
                        }
                        if ($max_range !== null && $max_range < $string_to_int) {
                            $can_fail = true;
                            continue;
                        }
                        if ($min_range !== null || $max_range !== null || $has_range === false) {
                            $filter_types[] = new T_Literal_Int($string_to_int);
                            continue;
                        }
                        // we don't know what the min/max of the range are
                        // and it might be out of the range too
                        $filter_types[] = $int_type;
                    } elseif ($atomic_type instanceof T_String) {
                        $filter_types[] = $int_type;
                    }
                    if ($atomic_type instanceof T_Bool) {
                        if ($min_range !== null && $min_range > 1) {
                            $can_fail = true;
                            continue;
                        }
                        if ($max_range !== null && $max_range < 1) {
                            $can_fail = true;
                            continue;
                        }
                        if ($atomic_type instanceof T_False) {
                            $can_fail = true;
                            continue;
                        }
                        if ($min_range !== null || $max_range !== null || $has_range === false) {
                            $filter_types[] = new T_Literal_Int(1);
                            if ($atomic_type instanceof T_True) {
                                continue;
                            }
                        }
                        // we don't know what the min/max of the range are
                        // and it might be out of the range too
                        $filter_types[] = $int_type;
                    }
                    if ($atomic_type instanceof T_Mixed || $atomic_type::class === T_Numeric::class || $atomic_type::class === T_Scalar::class) {
                        $filter_types[] = $int_type;
                    }
                    $can_fail = true;
                }
                break;
            case FILTER_VALIDATE_IP:
            case FILTER_VALIDATE_MAC:
            case FILTER_VALIDATE_URL:
            case FILTER_VALIDATE_EMAIL:
                foreach ($input_type->get_atomic_types() as $atomic_type) {
                    if ($atomic_type instanceof T_Numeric_String) {
                        $can_fail = true;
                        continue;
                    }
                    if ($atomic_type instanceof T_Non_Falsy_String) {
                        $filter_types[] = $atomic_type;
                    } elseif ($atomic_type instanceof T_String) {
                        $filter_types[] = new T_Non_Falsy_String();
                    } elseif ($atomic_type instanceof T_Mixed || $atomic_type instanceof T_Scalar) {
                        $filter_types[] = new T_Non_Falsy_String();
                    }
                    $can_fail = true;
                }
                break;
            case FILTER_VALIDATE_REGEXP:
                // the regexp key is mandatory for this filter
                // it will only fail if the value exists, therefore it's after the checks above
                // this must be (and is) handled BEFORE calling this function though
                // since PHP 8+ throws instead of returning the fails case
                if ($regexp === null) {
                    $can_fail = true;
                    break;
                }
                // invalid regex
                if ($regexp !== true && @preg_match($regexp, 'placeholder') === false) {
                    $can_fail = true;
                    break;
                }
                foreach ($input_type->get_atomic_types() as $atomic_type) {
                    if ($atomic_type instanceof T_String || $atomic_type instanceof T_Int || $atomic_type instanceof T_Float || $atomic_type instanceof T_Numeric || $atomic_type instanceof T_Scalar || $atomic_type instanceof T_Mixed) {
                        $filter_types[] = new T_String();
                    }
                    $can_fail = true;
                }
                break;
            case FILTER_VALIDATE_DOMAIN:
                if (self::has_flag($flags_int_used, FILTER_FLAG_HOSTNAME)) {
                    $string_type = new T_Non_Empty_String();
                } else {
                    $string_type = new T_String();
                }
                foreach ($input_type->get_atomic_types() as $atomic_type) {
                    if ($atomic_type instanceof T_Non_Empty_String || $atomic_type instanceof T_Non_Empty_Nonspecific_Literal_String) {
                        $filter_types[] = $atomic_type;
                    } elseif ($atomic_type instanceof T_String) {
                        if (self::has_flag($flags_int_used, FILTER_FLAG_HOSTNAME)) {
                            $filter_types[] = $string_type;
                        } else {
                            $filter_types[] = $atomic_type;
                        }
                    } elseif ($atomic_type instanceof T_Mixed || $atomic_type instanceof T_Int || $atomic_type instanceof T_Float || $atomic_type instanceof T_Scalar) {
                        $filter_types[] = $string_type;
                    }
                    $can_fail = true;
                }
                break;
            case FILTER_SANITIZE_EMAIL:
            case FILTER_SANITIZE_URL:
                foreach ($input_type->get_atomic_types() as $atomic_type) {
                    if ($atomic_type instanceof T_Numeric_String) {
                        $filter_types[] = $atomic_type;
                        continue;
                    }
                    if ($atomic_type instanceof T_String) {
                        $filter_types[] = new T_String();
                        continue;
                    }
                    if ($atomic_type instanceof T_Float || $atomic_type instanceof T_Int || $atomic_type instanceof T_Numeric) {
                        $filter_types[] = new T_Numeric_String();
                        continue;
                    }
                    if ($atomic_type instanceof T_True) {
                        $filter_types[] = Type::get_atomic_string_from_literal('1');
                        continue;
                    }
                    if ($atomic_type instanceof T_False) {
                        $filter_types[] = Type::get_atomic_string_from_literal('');
                        continue;
                    }
                    if ($atomic_type instanceof T_Bool) {
                        $filter_types[] = Type::get_atomic_string_from_literal('1');
                        $filter_types[] = Type::get_atomic_string_from_literal('');
                        continue;
                    }
                    if ($atomic_type instanceof T_Mixed || $atomic_type instanceof T_Scalar) {
                        $filter_types[] = new T_String();
                    }
                    $can_fail = true;
                }
                break;
            case FILTER_SANITIZE_ENCODED:
            case FILTER_SANITIZE_ADD_SLASHES:
            case 521:
            // 8.0.0 FILTER_SANITIZE_MAGIC_QUOTES has been removed.
            case FILTER_SANITIZE_SPECIAL_CHARS:
            case FILTER_SANITIZE_FULL_SPECIAL_CHARS:
            case FILTER_DEFAULT:
                foreach ($input_type->get_atomic_types() as $atomic_type) {
                    if ($filter_int_used === FILTER_DEFAULT && $flags_int_used === 0 && $atomic_type instanceof T_String) {
                        $filter_types[] = $atomic_type;
                        continue;
                    }
                    if ($atomic_type instanceof T_Numeric_String) {
                        $filter_types[] = $atomic_type;
                        continue;
                    }
                    if (in_array($filter_int_used, [FILTER_SANITIZE_ENCODED, FILTER_SANITIZE_SPECIAL_CHARS, FILTER_DEFAULT], true) && ($atomic_type instanceof T_Non_Empty_String || $atomic_type instanceof T_Non_Empty_Nonspecific_Literal_String) && (self::has_flag($flags_int_used, FILTER_FLAG_STRIP_LOW) || self::has_flag($flags_int_used, FILTER_FLAG_STRIP_HIGH) || self::has_flag($flags_int_used, FILTER_FLAG_STRIP_BACKTICK))) {
                        $filter_types[] = new T_String();
                        continue;
                    }
                    if ($atomic_type instanceof T_Non_Falsy_String) {
                        $filter_types[] = new T_Non_Falsy_String();
                        continue;
                    }
                    if ($atomic_type instanceof T_Non_Empty_String || $atomic_type instanceof T_Non_Empty_Nonspecific_Literal_String) {
                        $filter_types[] = new T_Non_Empty_String();
                        continue;
                    }
                    if ($atomic_type instanceof T_String) {
                        $filter_types[] = new T_String();
                        continue;
                    }
                    if ($atomic_type instanceof T_Float || $atomic_type instanceof T_Int || $atomic_type instanceof T_Numeric) {
                        $filter_types[] = new T_Numeric_String();
                        continue;
                    }
                    if ($atomic_type instanceof T_True) {
                        $filter_types[] = Type::get_atomic_string_from_literal('1');
                        continue;
                    }
                    if ($atomic_type instanceof T_False) {
                        $filter_types[] = Type::get_atomic_string_from_literal('');
                        continue;
                    }
                    if ($atomic_type instanceof T_Bool) {
                        $filter_types[] = Type::get_atomic_string_from_literal('1');
                        $filter_types[] = Type::get_atomic_string_from_literal('');
                        continue;
                    }
                    if ($atomic_type instanceof T_Mixed || $atomic_type instanceof T_Scalar) {
                        $filter_types[] = new T_String();
                    }
                    $can_fail = true;
                }
                break;
            case 513:
                // 8.1.0 FILTER_SANITIZE_STRING and FILTER_SANITIZE_STRIPPED (alias) have been deprecated.
                foreach ($input_type->get_atomic_types() as $atomic_type) {
                    if ($atomic_type instanceof T_Bool || $atomic_type instanceof T_String || $atomic_type instanceof T_Int || $atomic_type instanceof T_Float || $atomic_type instanceof T_Numeric) {
                        // only basic checking since it's deprecated anyway and not worth the time
                        $filter_types[] = new T_String();
                        continue;
                    }
                    if ($atomic_type instanceof T_Mixed || $atomic_type instanceof T_Scalar) {
                        $filter_types[] = new T_String();
                    }
                    $can_fail = true;
                }
                break;
            case FILTER_SANITIZE_NUMBER_INT:
            case FILTER_SANITIZE_NUMBER_FLOAT:
                foreach ($input_type->get_atomic_types() as $atomic_type) {
                    if ($atomic_type instanceof T_Literal_String || $atomic_type instanceof T_Literal_Int || $atomic_type instanceof T_Literal_Float) {
                        /** @var string|false $literal */
                        $literal = filter_var($atomic_type->value, $filter_int_used);
                        if ($literal === false) {
                            $can_fail = true;
                        } else {
                            $filter_types[] = Type::get_atomic_string_from_literal($literal);
                        }
                        continue;
                    }
                    if ($atomic_type instanceof T_Float || $atomic_type instanceof T_Numeric_String || $atomic_type instanceof T_Int || $atomic_type instanceof T_Numeric) {
                        $filter_types[] = new T_Numeric_String();
                        continue;
                    }
                    if ($atomic_type instanceof T_String) {
                        $filter_types[] = new T_Numeric_String();
                        // for numeric-string it won't collapse since https://github.com/vimeo/psalm/pull/10459
                        // therefore we can add both
                        $filter_types[] = Type::get_atomic_string_from_literal('');
                        continue;
                    }
                    if ($atomic_type instanceof T_True) {
                        $filter_types[] = Type::get_atomic_string_from_literal('1');
                        continue;
                    }
                    if ($atomic_type instanceof T_False) {
                        $filter_types[] = Type::get_atomic_string_from_literal('');
                        continue;
                    }
                    if ($atomic_type instanceof T_Bool) {
                        $filter_types[] = Type::get_atomic_string_from_literal('1');
                        $filter_types[] = Type::get_atomic_string_from_literal('');
                        continue;
                    }
                    if ($atomic_type instanceof T_Mixed || $atomic_type instanceof T_Scalar) {
                        $filter_types[] = new T_Numeric_String();
                        $filter_types[] = Type::get_atomic_string_from_literal('');
                    }
                    $can_fail = true;
                }
                break;
        }
        if ($input_type->has_mixed()) {
            // can always fail if we have mixed
            // only for redundancy in case there's a mistake in the switch above
            $can_fail = true;
        }
        // if an array is required, ignore all types we created from non-array on first level
        if (!$in_array_recursion && self::has_flag($flags_int_used, FILTER_REQUIRE_ARRAY)) {
            $filter_types = [];
        }
        $return_type = $fails_type;
        if ($filter_types !== [] && ($can_fail === true || !$in_array_recursion && !$not_set_type && $input_type->possibly_undefined)) {
            $return_type = Type::combine_union_types($return_type, Type_Combiner::combine($filter_types, $codebase), $codebase);
        } elseif ($filter_types !== []) {
            $return_type = Type_Combiner::combine($filter_types, $codebase);
        }
        if (!$in_array_recursion && !self::has_flag($flags_int_used, FILTER_REQUIRE_ARRAY) && self::has_flag($flags_int_used, FILTER_FORCE_ARRAY)) {
            $return_type = new Union([new T_Keyed_Array([$return_type], null, null, true)]);
        }
        if ($from_array !== []) {
            $from_array_union = Type_Combiner::combine($from_array, $codebase);
            $return_type = Type::combine_union_types($return_type, $from_array_union, $codebase);
        }
        if ($in_array_recursion && $input_type->possibly_undefined) {
            $return_type = $return_type->set_possibly_undefined(true);
        } elseif (!$in_array_recursion && $not_set_type && $input_type->possibly_undefined) {
            // in case of PHP CLI it will always fail for all filter_input even when they're set
            // to fix this we would have to add support for environments in Context
            // e.g. if php_sapi_name() === 'cli'
            $return_type = Type::combine_union_types(
                $return_type,
                // the not set type is not coerced into an array when FILTER_FORCE_ARRAY is used
                $not_set_type,
                $codebase
            );
        }
        if (!$in_array_recursion) {
            return self::add_return_taint($statements_analyzer, $code_location, $return_type, $function_id);
        }
        return $return_type;
    }
    private static function add_return_taint(Statements_Analyzer $statements_analyzer, Code_Location $code_location, Union $return_type, string $function_id): Union
    {
        if ($statements_analyzer->data_flow_graph && !in_array('TaintedInput', $statements_analyzer->get_suppressed_issues())) {
            $function_return_sink = Data_Flow_Node::get_for_method_return($function_id, $function_id, null, $code_location);
            $statements_analyzer->data_flow_graph->add_node($function_return_sink);
            $function_param_sink = Data_Flow_Node::get_for_method_argument($function_id, $function_id, 0, null, $code_location);
            $statements_analyzer->data_flow_graph->add_node($function_param_sink);
            $statements_analyzer->data_flow_graph->add_path($function_param_sink, $function_return_sink, 'arg');
            $return_type = $return_type->set_parent_nodes([$function_return_sink->id => $function_return_sink]);
        }
        return $return_type;
    }
    /** @return array<int, array{flags: list<int>, options: array<string, Union>}> */
    public static function get_filters(Codebase $codebase): array
    {
        $general_filter_flags = [FILTER_REQUIRE_SCALAR, FILTER_REQUIRE_ARRAY, FILTER_FORCE_ARRAY, FILTER_FLAG_NONE];
        // https://www.php.net/manual/en/filter.filters.sanitize.php
        $sanitize_filters = [FILTER_SANITIZE_EMAIL => ['flags' => [], 'options' => []], FILTER_SANITIZE_ENCODED => ['flags' => [FILTER_FLAG_STRIP_LOW, FILTER_FLAG_STRIP_HIGH, FILTER_FLAG_STRIP_BACKTICK, FILTER_FLAG_ENCODE_LOW, FILTER_FLAG_ENCODE_HIGH], 'options' => []], FILTER_SANITIZE_NUMBER_FLOAT => ['flags' => [FILTER_FLAG_ALLOW_FRACTION, FILTER_FLAG_ALLOW_THOUSAND, FILTER_FLAG_ALLOW_SCIENTIFIC], 'options' => []], FILTER_SANITIZE_NUMBER_INT => ['flags' => [], 'options' => []], FILTER_SANITIZE_SPECIAL_CHARS => ['flags' => [FILTER_FLAG_STRIP_LOW, FILTER_FLAG_STRIP_HIGH, FILTER_FLAG_STRIP_BACKTICK, FILTER_FLAG_ENCODE_HIGH], 'options' => []], FILTER_SANITIZE_FULL_SPECIAL_CHARS => ['flags' => [FILTER_FLAG_NO_ENCODE_QUOTES], 'options' => []], FILTER_SANITIZE_URL => ['flags' => [], 'options' => []], FILTER_UNSAFE_RAW => ['flags' => [FILTER_FLAG_STRIP_LOW, FILTER_FLAG_STRIP_HIGH, FILTER_FLAG_STRIP_BACKTICK, FILTER_FLAG_ENCODE_LOW, FILTER_FLAG_ENCODE_HIGH, FILTER_FLAG_ENCODE_AMP], 'options' => []]];
        if ($codebase->analysis_php_version_id <= 70300) {
            // FILTER_SANITIZE_MAGIC_QUOTES
            $sanitize_filters[521] = ['flags' => [], 'options' => []];
        }
        if ($codebase->analysis_php_version_id <= 80100) {
            // FILTER_SANITIZE_STRING
            $sanitize_filters[513] = ['flags' => [FILTER_FLAG_NO_ENCODE_QUOTES, FILTER_FLAG_STRIP_LOW, FILTER_FLAG_STRIP_HIGH, FILTER_FLAG_STRIP_BACKTICK, FILTER_FLAG_ENCODE_LOW, FILTER_FLAG_ENCODE_HIGH, FILTER_FLAG_ENCODE_AMP], 'options' => []];
        }
        if ($codebase->analysis_php_version_id >= 70300) {
            // was added as a replacement for FILTER_SANITIZE_MAGIC_QUOTES
            $sanitize_filters[FILTER_SANITIZE_ADD_SLASHES] = ['flags' => [], 'options' => []];
        }
        foreach ($sanitize_filters as $filter_int => $filter_data) {
            $sanitize_filters[$filter_int]['flags'] = array_merge($filter_data['flags'], $general_filter_flags);
        }
        // https://www.php.net/manual/en/filter.filters.validate.php
        // validation filters all match bitmask 0x100
        // all support FILTER_NULL_ON_FAILURE flag https://www.php.net/manual/en/filter.filters.flags.php
        $general_filter_flags_validate = array_merge($general_filter_flags, [FILTER_NULL_ON_FAILURE]);
        $validate_filters = [FILTER_VALIDATE_BOOLEAN => ['flags' => [], 'options' => []], FILTER_VALIDATE_EMAIL => ['flags' => [FILTER_FLAG_EMAIL_UNICODE], 'options' => []], FILTER_VALIDATE_FLOAT => ['flags' => [FILTER_FLAG_ALLOW_THOUSAND], 'options' => ['decimal' => new Union([Type::get_atomic_string_from_literal('.'), Type::get_atomic_string_from_literal(',')])]], FILTER_VALIDATE_INT => ['flags' => [FILTER_FLAG_ALLOW_OCTAL, FILTER_FLAG_ALLOW_HEX], 'options' => ['min_range' => Type::get_numeric(), 'max_range' => Type::get_numeric()]], FILTER_VALIDATE_IP => ['flags' => [FILTER_FLAG_IPV4, FILTER_FLAG_IPV6, FILTER_FLAG_NO_PRIV_RANGE, FILTER_FLAG_NO_RES_RANGE], 'options' => []], FILTER_VALIDATE_MAC => ['flags' => [], 'options' => []], FILTER_VALIDATE_REGEXP => ['flags' => [], 'options' => ['regexp' => Type::get_non_falsy_string()]], FILTER_VALIDATE_URL => ['flags' => [FILTER_FLAG_PATH_REQUIRED, FILTER_FLAG_QUERY_REQUIRED], 'options' => []]];
        if ($codebase->analysis_php_version_id >= 70400) {
            $validate_filters[FILTER_VALIDATE_FLOAT]['options']['min_range'] = Type::get_numeric();
            $validate_filters[FILTER_VALIDATE_FLOAT]['options']['max_range'] = Type::get_numeric();
        }
        if ($codebase->analysis_php_version_id < 80000) {
            // phpcs:ignore SlevomatCodingStandard.Numbers.RequireNumericLiteralSeparator.RequiredNumericLiteralSeparator
            $validate_filters[FILTER_VALIDATE_URL]['flags'][] = 65536;
            // FILTER_FLAG_SCHEME_REQUIRED
            // phpcs:ignore SlevomatCodingStandard.Numbers.RequireNumericLiteralSeparator.RequiredNumericLiteralSeparator
            $validate_filters[FILTER_VALIDATE_URL]['flags'][] = 131072;
            // FILTER_FLAG_HOST_REQUIRED
        }
        if ($codebase->analysis_php_version_id >= 80200) {
            // phpcs:ignore SlevomatCodingStandard.Numbers.RequireNumericLiteralSeparator.RequiredNumericLiteralSeparator
            $validate_filters[FILTER_VALIDATE_IP]['flags'][] = 268435456;
            // FILTER_FLAG_GLOBAL_RANGE
        }
        if ($codebase->analysis_php_version_id >= 70000) {
            $validate_filters[FILTER_VALIDATE_DOMAIN] = ['flags' => [FILTER_FLAG_HOSTNAME], 'options' => []];
        }
        foreach ($validate_filters as $filter_int => $filter_data) {
            $validate_filters[$filter_int]['flags'] = array_merge($filter_data['flags'], $general_filter_flags_validate);
            $default_options = ['default' => Type::get_mixed()];
            $validate_filters[$filter_int]['options'] = array_merge($filter_data['options'], $default_options);
        }
        // https://www.php.net/manual/en/filter.filters.misc.php
        $other_filters = [FILTER_CALLBACK => [
            // the docs say that all flags are ignored
            // however this seems to be incorrect https://github.com/php/doc-en/issues/2708
            // however they can only be used in the options array, not as a param directly
            'flags' => $general_filter_flags_validate,
            // the options array is required for this filter
            // and must be a valid callback instead of an array like in other cases
            'options' => [],
        ]];
        return $sanitize_filters + $validate_filters + $other_filters;
    }
}