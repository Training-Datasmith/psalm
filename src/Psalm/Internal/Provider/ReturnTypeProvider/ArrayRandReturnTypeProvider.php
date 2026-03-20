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
use Psalm\Type\Union;
/**
 * @internal
 */
final class Array_Rand_Return_Type_Provider implements Function_Return_Type_Provider_Interface
{
    /**
     * @return array<lowercase-string>
     */
    #[Override]
    public static function get_function_ids(): array
    {
        return ['array_rand'];
    }
    #[Override]
    public static function get_function_return_type(Function_Return_Type_Provider_Event $event): Union
    {
        $statements_source = $event->get_statements_source();
        $call_args = $event->get_call_args();
        if (!$statements_source instanceof Statements_Analyzer) {
            return Type::get_mixed();
        }
        $first_arg = $call_args[0]->value ?? null;
        $second_arg = $call_args[1]->value ?? null;
        $first_arg_array = $first_arg && ($first_arg_type = $statements_source->node_data->get_type($first_arg)) && $first_arg_type->has_type('array') && ($array_atomic_type = $first_arg_type->get_array()) && ($array_atomic_type instanceof T_Array || $array_atomic_type instanceof T_Keyed_Array) ? $array_atomic_type : null;
        if (!$first_arg_array) {
            return Type::get_mixed();
        }
        if ($first_arg_array instanceof T_Array) {
            $key_type = $first_arg_array->type_params[0];
        } else {
            $key_type = $first_arg_array->get_generic_key_type();
        }
        if (!$second_arg) {
            return $key_type;
        }
        $second_arg_type = $statements_source->node_data->get_type($second_arg);
        if ($second_arg_type && $second_arg_type->is_single_int_literal() && $second_arg_type->get_single_int_literal()->value === 1) {
            return $key_type;
        }
        $arr_type = Type::get_list($key_type);
        if ($second_arg_type && $second_arg_type->is_single_int_literal()) {
            return $arr_type;
        }
        return Type::combine_union_types($key_type, $arr_type);
    }
}