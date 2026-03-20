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
final class Array_Splice_Return_Type_Provider implements Function_Return_Type_Provider_Interface
{
    /**
     * @return array<lowercase-string>
     */
    #[Override]
    public static function get_function_ids(): array
    {
        return ['array_splice'];
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
        $array_type = $first_arg && ($first_arg_type = $statements_source->node_data->get_type($first_arg)) && $first_arg_type->has_type('array') && ($array_atomic_type = $first_arg_type->get_array()) && ($array_atomic_type instanceof T_Array || $array_atomic_type instanceof T_Keyed_Array) ? $array_atomic_type : null;
        if (!$array_type) {
            return Type::get_array();
        }
        if ($array_type instanceof T_Keyed_Array) {
            $array_type = $array_type->get_generic_array_type();
        }
        if (!$array_type->type_params[0]->has_string()) {
            if ($array_type->type_params[1]->is_string()) {
                $array_type = Type::get_list_atomic(Type::get_string());
            } elseif ($array_type->type_params[1]->is_int()) {
                $array_type = Type::get_list_atomic(Type::get_int());
            } else {
                $array_type = Type::get_list_atomic(Type::get_mixed());
            }
        }
        return new Union([$array_type]);
    }
}