<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider\Return_Type_Provider;

use Override;
use Psalm\Plugin\Event_Handler\Event\Function_Return_Type_Provider_Event;
use Psalm\Plugin\Event_Handler\Function_Return_Type_Provider_Interface;
use Psalm\Type;
use Psalm\Type\Atomic\T_Int_Range;
use Psalm\Type\Atomic\T_Literal_Int;
use Psalm\Type\Union;
use function count;
/**
 * @internal
 */
final class Rand_Return_Type_Provider implements Function_Return_Type_Provider_Interface
{
    /**
     * @return array<lowercase-string>
     */
    #[Override]
    public static function get_function_ids(): array
    {
        return ['rand', 'mt_rand', 'random_int'];
    }
    #[Override]
    public static function get_function_return_type(Function_Return_Type_Provider_Event $event): ?Union
    {
        $call_args = $event->get_call_args();
        if (count($call_args) === 0) {
            return Type::get_int();
        }
        if (count($call_args) !== 2) {
            return null;
        }
        $statements_source = $event->get_statements_source();
        $node_type_provider = $statements_source->get_node_type_provider();
        $first_arg = $node_type_provider->get_type($call_args[0]->value);
        $second_arg = $node_type_provider->get_type($call_args[1]->value);
        $min_value = null;
        if ($first_arg !== null && $first_arg->is_single()) {
            $first_atomic_type = $first_arg->get_single_atomic();
            if ($first_atomic_type instanceof T_Literal_Int) {
                $min_value = $first_atomic_type->value;
            } elseif ($first_atomic_type instanceof T_Int_Range) {
                $min_value = $first_atomic_type->min_bound;
            }
        }
        $max_value = null;
        if ($second_arg !== null && $second_arg->is_single()) {
            $second_atomic_type = $second_arg->get_single_atomic();
            if ($second_atomic_type instanceof T_Literal_Int) {
                $max_value = $second_atomic_type->value;
            } elseif ($second_atomic_type instanceof T_Int_Range) {
                $max_value = $second_atomic_type->max_bound;
            }
        }
        return Type::get_int_range($min_value, $max_value);
    }
}