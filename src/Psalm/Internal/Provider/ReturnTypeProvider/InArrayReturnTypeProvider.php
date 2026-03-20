<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider\Return_Type_Provider;

use Override;
use Psalm\Internal\Type\Comparator\Union_Type_Comparator;
use Psalm\Plugin\Event_Handler\Event\Function_Return_Type_Provider_Event;
use Psalm\Plugin\Event_Handler\Function_Return_Type_Provider_Interface;
use Psalm\Type;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Union;
/**
 * @internal
 */
final class In_Array_Return_Type_Provider implements Function_Return_Type_Provider_Interface
{
    /**
     * @return array<lowercase-string>
     */
    #[Override]
    public static function get_function_ids(): array
    {
        return ['in_array'];
    }
    #[Override]
    public static function get_function_return_type(Function_Return_Type_Provider_Event $event): Union
    {
        $call_args = $event->get_call_args();
        $bool = Type::get_bool();
        if (!isset($call_args[0]) || !isset($call_args[1])) {
            return $bool;
        }
        $needle_type = $event->get_statements_source()->get_node_type_provider()->get_type($call_args[0]->value);
        $haystack_type = $event->get_statements_source()->get_node_type_provider()->get_type($call_args[1]->value);
        if ($needle_type === null || $haystack_type === null) {
            return $bool;
        }
        $false = Type::get_false();
        /** @psalm-suppress InaccessibleProperty We just created these types */
        $false->from_docblock = $bool->from_docblock = $needle_type->from_docblock || $haystack_type->from_docblock;
        if (!isset($call_args[2])) {
            return $bool;
        }
        $strict_type = $event->get_statements_source()->get_node_type_provider()->get_type($call_args[2]->value);
        if ($strict_type === null || !$strict_type->is_true()) {
            return $bool;
        }
        /**
         * @var TKeyedArray|TArray|null
         */
        $array_arg_type = ($types = $haystack_type->get_atomic_types()) && isset($types['array']) ? $types['array'] : null;
        if ($array_arg_type instanceof T_Keyed_Array) {
            $array_arg_type = $array_arg_type->get_generic_array_type();
        }
        if (!$array_arg_type instanceof T_Array) {
            return $bool;
        }
        $haystack_item_type = $array_arg_type->type_params[1];
        if (Union_Type_Comparator::can_expression_types_be_identical($event->get_statements_source()->get_codebase(), $needle_type, $haystack_item_type)) {
            return $bool;
        }
        return $false;
    }
}