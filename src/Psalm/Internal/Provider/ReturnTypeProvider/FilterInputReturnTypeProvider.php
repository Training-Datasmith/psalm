<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider\Return_Type_Provider;

use Override;
use Psalm\Internal\Analyzer\Statements\Expression\Fetch\Variable_Fetch_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Plugin\Event_Handler\Event\Function_Return_Type_Provider_Event;
use Psalm\Plugin\Event_Handler\Function_Return_Type_Provider_Interface;
use Psalm\Type;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Union;
use UnexpectedValueException;
use function array_flip;
use function array_search;
use function in_array;
use function is_array;
use function is_int;
use const FILTER_CALLBACK;
use const FILTER_DEFAULT;
use const FILTER_FLAG_NONE;
use const FILTER_REQUIRE_ARRAY;
use const FILTER_VALIDATE_REGEXP;
use const INPUT_COOKIE;
use const INPUT_ENV;
use const INPUT_GET;
use const INPUT_POST;
use const INPUT_SERVER;
/**
 * @internal
 */
final class Filter_Input_Return_Type_Provider implements Function_Return_Type_Provider_Interface
{
    /**
     * @return array<lowercase-string>
     */
    #[Override]
    public static function get_function_ids(): array
    {
        return ['filter_input'];
    }
    #[Override]
    public static function get_function_return_type(Function_Return_Type_Provider_Event $event): ?Union
    {
        $statements_analyzer = $event->get_statements_source();
        if (!$statements_analyzer instanceof Statements_Analyzer) {
            throw new UnexpectedValueException('Expected StatementsAnalyzer not StatementsSource');
        }
        $arg_names = array_flip(['type', 'var_name', 'filter', 'options']);
        $call_args = [];
        foreach ($event->get_call_args() as $idx => $arg) {
            if (isset($arg->name)) {
                $call_args[$arg_names[$arg->name->name]] = $arg;
            } else {
                $call_args[$idx] = $arg;
            }
        }
        $function_id = $event->get_function_id();
        $code_location = $event->get_code_location();
        $codebase = $statements_analyzer->get_codebase();
        if (!isset($call_args[0]) || !isset($call_args[1])) {
            return Filter_Utils::missing_first_arg($codebase);
        }
        $first_arg_type = $statements_analyzer->node_data->get_type($call_args[0]->value);
        if ($first_arg_type && !$first_arg_type->is_int()) {
            if ($codebase->analysis_php_version_id >= 80000) {
                // throws
                return Type::get_never();
            }
            // default option won't be used in this case
            return Type::get_null();
        }
        $filter_int_used = FILTER_DEFAULT;
        if (isset($call_args[2])) {
            $filter_int_used = Filter_Utils::get_filter_arg_value_or_error($call_args[2], $statements_analyzer, $codebase);
            if (!is_int($filter_int_used)) {
                return $filter_int_used;
            }
        }
        $options = null;
        $flags_int_used = FILTER_FLAG_NONE;
        if (isset($call_args[3])) {
            $helper = Filter_Utils::get_options_arg_value_or_error($call_args[3], $statements_analyzer, $codebase, $code_location, $function_id, $filter_int_used);
            if (!is_array($helper)) {
                return $helper;
            }
            $flags_int_used = $helper['flags_int_used'];
            $options = $helper['options'];
        }
        // if we reach this point with callback, the callback is missing
        if ($filter_int_used === FILTER_CALLBACK) {
            return Filter_Utils::missing_filter_callback_callable($function_id, $code_location, $statements_analyzer, $codebase);
        }
        [$default, $min_range, $max_range, $has_range, $regexp] = Filter_Utils::get_options($filter_int_used, $flags_int_used, $options, $statements_analyzer, $code_location, $codebase, $function_id);
        // only return now, as we still want to report errors above
        if (!$first_arg_type) {
            return null;
        }
        if (!$first_arg_type->is_single_int_literal()) {
            // eventually complex cases can be handled too, however practically this is irrelevant
            return null;
        }
        if (!$default) {
            [$fails_type, $not_set_type, $fails_or_not_set_type] = Filter_Utils::get_fails_not_set_type($flags_int_used);
        } else {
            $fails_type = $default;
            $not_set_type = $default;
            $fails_or_not_set_type = $default;
        }
        if ($filter_int_used === FILTER_VALIDATE_REGEXP && $regexp === null) {
            if ($codebase->analysis_php_version_id >= 80000) {
                // throws
                return Type::get_never();
            }
            // any "array" flags are ignored by this filter!
            return $fails_or_not_set_type;
        }
        $possible_types = ['$_GET' => INPUT_GET, '$_POST' => INPUT_POST, '$_COOKIE' => INPUT_COOKIE, '$_SERVER' => INPUT_SERVER, '$_ENV' => INPUT_ENV];
        $first_arg_type_type = $first_arg_type->get_single_int_literal();
        $global_name = array_search($first_arg_type_type->value, $possible_types);
        if (!$global_name) {
            // invalid
            if ($codebase->analysis_php_version_id >= 80000) {
                // throws
                return Type::get_never();
            }
            // the "not set type" is never in an array, even if FILTER_FORCE_ARRAY is set!
            return $not_set_type;
        }
        $second_arg_type = $statements_analyzer->node_data->get_type($call_args[1]->value);
        if (!$second_arg_type) {
            return null;
        }
        if (!$second_arg_type->has_string()) {
            // for filter_input there can only be string array keys
            return $not_set_type;
        }
        if (!$second_arg_type->is_string()) {
            // already reports an error by default
            return null;
        }
        // in all these cases it can fail or be not set, depending on whether the variable is set or not
        $redundant_error_return_type = Filter_Utils::check_redundant_flags($filter_int_used, $flags_int_used, $fails_or_not_set_type, $statements_analyzer, $code_location, $codebase);
        if ($redundant_error_return_type !== null) {
            return $redundant_error_return_type;
        }
        if (Filter_Utils::has_flag($flags_int_used, FILTER_REQUIRE_ARRAY) && in_array($first_arg_type_type->value, [INPUT_COOKIE, INPUT_SERVER, INPUT_ENV], true)) {
            // these globals can never be an array
            return $fails_or_not_set_type;
        }
        // @todo eventually this needs to be changed when we fully support filter_has_var
        $global_type = Variable_Fetch_Analyzer::get_global_type($global_name, $codebase->analysis_php_version_id);
        $input_type = null;
        if ($global_type->is_array() && $global_type->get_array() instanceof T_Keyed_Array) {
            $array_instance = $global_type->get_array();
            if ($second_arg_type->is_single_string_literal()) {
                $key = $second_arg_type->get_single_string_literal()->value;
                if (isset($array_instance->properties[$key])) {
                    $input_type = $array_instance->properties[$key];
                }
            }
            if ($input_type === null) {
                $input_type = $array_instance->get_generic_value_type();
                $input_type = $input_type->set_possibly_undefined(true);
            }
        } elseif ($global_type->is_array() && ($array_atomic = $global_type->get_array()) && $array_atomic instanceof T_Array) {
            [$_, $input_type] = $array_atomic->type_params;
            $input_type = $input_type->set_possibly_undefined(true);
        } else {
            return null;
        }
        return Filter_Utils::get_return_type($filter_int_used, $flags_int_used, $input_type, $fails_type, $not_set_type, $statements_analyzer, $code_location, $codebase, $function_id, $has_range, $min_range, $max_range, $regexp);
    }
}