<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider\Return_Type_Provider;

use Override;
use Psalm\Plugin\Event_Handler\Event\Function_Return_Type_Provider_Event;
use Psalm\Plugin\Event_Handler\Function_Return_Type_Provider_Interface;
use Psalm\Type;
use Psalm\Type\Atomic\T_Float;
use Psalm\Type\Atomic\T_Int;
use Psalm\Type\Atomic\T_Literal_Float;
use Psalm\Type\Atomic\T_Literal_Int;
use Psalm\Type\Union;
use function count;
/**
 * @internal
 */
final class Pow_Return_Type_Provider implements Function_Return_Type_Provider_Interface
{
    /**
     * @return array<lowercase-string>
     */
    #[Override]
    public static function get_function_ids(): array
    {
        return ['pow'];
    }
    #[Override]
    public static function get_function_return_type(Function_Return_Type_Provider_Event $event): ?Union
    {
        $call_args = $event->get_call_args();
        if (count($call_args) !== 2) {
            return null;
        }
        $first_arg = $event->get_statements_source()->get_node_type_provider()->get_type($call_args[0]->value);
        $second_arg = $event->get_statements_source()->get_node_type_provider()->get_type($call_args[1]->value);
        $first_arg_literal = null;
        $first_arg_is_int = false;
        $first_arg_is_float = false;
        if ($first_arg !== null && $first_arg->is_single()) {
            $first_atomic_type = $first_arg->get_single_atomic();
            if ($first_atomic_type instanceof T_Int) {
                $first_arg_is_int = true;
            } elseif ($first_atomic_type instanceof T_Float) {
                $first_arg_is_float = true;
            }
            if ($first_atomic_type instanceof T_Literal_Int || $first_atomic_type instanceof T_Literal_Float) {
                $first_arg_literal = $first_atomic_type->value;
            }
        }
        $second_arg_literal = null;
        $second_arg_is_int = false;
        $second_arg_is_float = false;
        if ($second_arg !== null && $second_arg->is_single()) {
            $second_atomic_type = $second_arg->get_single_atomic();
            if ($second_atomic_type instanceof T_Int) {
                $second_arg_is_int = true;
            } elseif ($second_atomic_type instanceof T_Float) {
                $second_arg_is_float = true;
            }
            if ($second_atomic_type instanceof T_Literal_Int || $second_atomic_type instanceof T_Literal_Float) {
                $second_arg_literal = $second_atomic_type->value;
            }
        }
        if ($first_arg_literal === 0) {
            return Type::get_int(true, 0);
        }
        if ($second_arg_literal === 0) {
            return Type::get_int(true, 1);
        }
        if ($first_arg_literal !== null && $second_arg_literal !== null) {
            return Type::get_float($first_arg_literal ** $second_arg_literal);
        }
        if ($first_arg_is_int && $second_arg_is_int) {
            return Type::get_int();
        }
        if ($first_arg_is_float || $second_arg_is_float) {
            return Type::get_float();
        }
        return new Union([new T_Int(), new T_Float()]);
    }
}