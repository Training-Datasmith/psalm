<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider\Return_Type_Provider;

use Override;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Plugin\Event_Handler\Event\Function_Return_Type_Provider_Event;
use Psalm\Plugin\Event_Handler\Function_Return_Type_Provider_Interface;
use Psalm\Type;
use Psalm\Type\Union;
use UnexpectedValueException;
use function array_flip;
use function is_array;
use function is_int;
use const FILTER_CALLBACK;
use const FILTER_DEFAULT;
use const FILTER_FLAG_NONE;
use const FILTER_VALIDATE_REGEXP;
/**
 * @internal
 */
final class Filter_Var_Return_Type_Provider implements Function_Return_Type_Provider_Interface
{
    /**
     * @return array<lowercase-string>
     */
    #[Override]
    public static function get_function_ids(): array
    {
        return ['filter_var'];
    }
    #[Override]
    public static function get_function_return_type(Function_Return_Type_Provider_Event $event): ?Union
    {
        $statements_analyzer = $event->get_statements_source();
        if (!$statements_analyzer instanceof Statements_Analyzer) {
            throw new UnexpectedValueException();
        }
        $arg_names = array_flip(['value', 'filter', 'options']);
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
        if (!isset($call_args[0])) {
            return Filter_Utils::missing_first_arg($codebase);
        }
        $filter_int_used = FILTER_DEFAULT;
        if (isset($call_args[1])) {
            $filter_int_used = Filter_Utils::get_filter_arg_value_or_error($call_args[1], $statements_analyzer, $codebase);
            if (!is_int($filter_int_used)) {
                return $filter_int_used;
            }
        }
        $options = null;
        $flags_int_used = FILTER_FLAG_NONE;
        if (isset($call_args[2])) {
            $helper = Filter_Utils::get_options_arg_value_or_error($call_args[2], $statements_analyzer, $codebase, $code_location, $function_id, $filter_int_used);
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
        if (!$default) {
            [$fails_type] = Filter_Utils::get_fails_not_set_type($flags_int_used);
        } else {
            $fails_type = $default;
        }
        if ($filter_int_used === FILTER_VALIDATE_REGEXP && $regexp === null) {
            if ($codebase->analysis_php_version_id >= 80000) {
                // throws
                return Type::get_never();
            }
            // any "array" flags are ignored by this filter!
            return $fails_type;
        }
        $input_type = $statements_analyzer->node_data->get_type($call_args[0]->value);
        // only return now, as we still want to report errors above
        if (!$input_type) {
            return null;
        }
        $redundant_error_return_type = Filter_Utils::check_redundant_flags($filter_int_used, $flags_int_used, $fails_type, $statements_analyzer, $code_location, $codebase);
        if ($redundant_error_return_type !== null) {
            return $redundant_error_return_type;
        }
        return Filter_Utils::get_return_type($filter_int_used, $flags_int_used, $input_type, $fails_type, null, $statements_analyzer, $code_location, $codebase, $function_id, $has_range, $min_range, $max_range, $regexp);
    }
}