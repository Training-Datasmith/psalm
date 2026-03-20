<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider\Return_Type_Provider;

use Override;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Plugin\Event_Handler\Event\Function_Return_Type_Provider_Event;
use Psalm\Plugin\Event_Handler\Function_Return_Type_Provider_Interface;
use Psalm\Type;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Atomic\T_Non_Empty_Array;
use Psalm\Type\Union;
/**
 * @internal
 */
final class Array_Fill_Return_Type_Provider implements Function_Return_Type_Provider_Interface
{
    /**
     * @return array<lowercase-string>
     */
    #[Override]
    public static function get_function_ids(): array
    {
        return ['array_fill'];
    }
    #[Override]
    public static function get_function_return_type(Function_Return_Type_Provider_Event $event): Union
    {
        $statements_source = $event->get_statements_source();
        $call_args = $event->get_call_args();
        if (!$statements_source instanceof Statements_Analyzer) {
            return Type::get_mixed();
        }
        $codebase = $statements_source->get_codebase();
        $first_arg_type = isset($call_args[0]) ? $statements_source->node_data->get_type($call_args[0]->value) : null;
        $second_arg_type = isset($call_args[1]) ? $statements_source->node_data->get_type($call_args[1]->value) : null;
        $third_arg_type = isset($call_args[2]) ? $statements_source->node_data->get_type($call_args[2]->value) : null;
        $value_type_from_third_arg = $third_arg_type ?: Type::get_mixed();
        if ($first_arg_type && $second_arg_type && $third_arg_type && $first_arg_type->is_single_int_literal() && $second_arg_type->is_single_int_literal()) {
            $first_arg_type = $first_arg_type->get_single_int_literal()->value;
            $second_arg_type = $second_arg_type->get_single_int_literal()->value;
            $is_list = $first_arg_type === 0;
            if ($second_arg_type < 0) {
                if ($codebase->analysis_php_version_id < 80000) {
                    return Type::get_false();
                }
                return Type::get_never();
            }
            $result = [];
            if ($first_arg_type < 0 && $codebase->analysis_php_version_id < 80000) {
                $result[$first_arg_type] = $third_arg_type;
                $first_arg_type = 0;
                $second_arg_type--;
            }
            while ($second_arg_type > 0) {
                $result[$first_arg_type++] = $third_arg_type;
                $second_arg_type--;
            }
            if (!$result) {
                return Type::get_empty_array();
            }
            return new Union([new T_Keyed_Array($result, null, null, $is_list)]);
        }
        if ($first_arg_type && $first_arg_type->is_single_int_literal() && $first_arg_type->get_single_int_literal()->value === 0) {
            if ($second_arg_type && self::is_positive_numeric_type($second_arg_type)) {
                return Type::get_non_empty_list($value_type_from_third_arg);
            }
            return Type::get_list($value_type_from_third_arg);
        }
        if ($second_arg_type && self::is_positive_numeric_type($second_arg_type)) {
            if ($first_arg_type && $first_arg_type->is_single_int_literal() && $second_arg_type->is_single_int_literal()) {
                return new Union([new T_Non_Empty_Array([Type::get_int_range($first_arg_type->get_single_int_literal()->value, $second_arg_type->get_single_int_literal()->value), $value_type_from_third_arg])]);
            }
            return new Union([new T_Non_Empty_Array([Type::get_int(), $value_type_from_third_arg])]);
        }
        return new Union([new T_Array([Type::get_int(), $value_type_from_third_arg])]);
    }
    private static function is_positive_numeric_type(Union $arg): bool
    {
        if ($arg->is_single()) {
            foreach ($arg->get_range_ints() as $range_int) {
                if ($range_int->is_positive()) {
                    return true;
                }
            }
        }
        return $arg->is_single_int_literal() && $arg->get_single_int_literal()->value > 0;
    }
}