<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider\Return_Type_Provider;

use Override;
use Psalm\Internal\Type\Array_Type;
use Psalm\Plugin\Event_Handler\Event\Function_Return_Type_Provider_Event;
use Psalm\Plugin\Event_Handler\Function_Return_Type_Provider_Interface;
use Psalm\Type;
use Psalm\Type\Atomic\T_Non_Empty_Array;
use Psalm\Type\Union;
use function count;
/**
 * @internal
 */
final class Array_Chunk_Return_Type_Provider implements Function_Return_Type_Provider_Interface
{
    /**
     * @return array<lowercase-string>
     */
    #[Override]
    public static function get_function_ids(): array
    {
        return ['array_chunk'];
    }
    #[Override]
    public static function get_function_return_type(Function_Return_Type_Provider_Event $event): \Psalm\Type\Union
    {
        $call_args = $event->get_call_args();
        $statements_source = $event->get_statements_source();
        if (count($call_args) >= 2 && ($array_arg_type = $statements_source->get_node_type_provider()->get_type($call_args[0]->value)) && $array_arg_type->is_single() && $array_arg_type->has_array() && $array_type = Array_Type::infer($array_arg_type->get_array())) {
            $preserve_keys = isset($call_args[2]) && ($preserve_keys_arg_type = $statements_source->get_node_type_provider()->get_type($call_args[2]->value)) && (string) $preserve_keys_arg_type !== 'false';
            return Type::get_list(new Union([$preserve_keys ? new T_Non_Empty_Array([$array_type->key, $array_type->value]) : Type::get_non_empty_list_atomic($array_type->value)]));
        }
        return new Union([Type::get_list_atomic(Type::get_array())]);
    }
}