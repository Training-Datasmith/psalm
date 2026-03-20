<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider\Return_Type_Provider;

use Override;
use Psalm\Internal\Type\Array_Type;
use Psalm\Plugin\Event_Handler\Event\Function_Return_Type_Provider_Event;
use Psalm\Plugin\Event_Handler\Function_Return_Type_Provider_Interface;
use Psalm\Type;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Non_Empty_Array;
use Psalm\Type\Union;
use function count;
/**
 * @internal
 */
final class Array_Pad_Return_Type_Provider implements Function_Return_Type_Provider_Interface
{
    /**
     * @return array<lowercase-string>
     */
    #[Override]
    public static function get_function_ids(): array
    {
        return ['array_pad'];
    }
    #[Override]
    public static function get_function_return_type(Function_Return_Type_Provider_Event $event): \Psalm\Type\Union
    {
        $statements_source = $event->get_statements_source();
        $call_args = $event->get_call_args();
        $type_provider = $statements_source->get_node_type_provider();
        if (count($call_args) >= 3 && ($array_arg_type = $type_provider->get_type($call_args[0]->value)) && ($size_arg_type = $type_provider->get_type($call_args[1]->value)) && ($value_arg_type = $type_provider->get_type($call_args[2]->value)) && $array_arg_type->is_single() && $array_arg_type->has_array() && $array_type = Array_Type::infer($array_arg_type->get_array())) {
            $codebase = $statements_source->get_codebase();
            $key_type = Type::combine_union_types($array_type->key, Type::get_int(), $codebase);
            $value_type = Type::combine_union_types($array_type->value, $value_arg_type, $codebase);
            $can_return_empty = !$size_arg_type->is_single_int_literal() || $size_arg_type->get_single_int_literal()->value === 0;
            return new Union([$array_type->is_list ? $can_return_empty ? Type::get_list_atomic($value_type) : Type::get_non_empty_list_atomic($value_type) : ($can_return_empty ? new T_Array([$key_type, $value_type]) : new T_Non_Empty_Array([$key_type, $value_type]))]);
        }
        return Type::get_array();
    }
}