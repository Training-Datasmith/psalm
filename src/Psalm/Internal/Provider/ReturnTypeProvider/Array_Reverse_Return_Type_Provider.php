<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider\Return_Type_Provider;

use Override;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Type\Type_Combiner;
use Psalm\Plugin\Event_Handler\Event\Function_Return_Type_Provider_Event;
use Psalm\Plugin\Event_Handler\Function_Return_Type_Provider_Interface;
use Psalm\Type;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Union;
use function array_reverse;
use function array_values;
use function count;
/**
 * @internal
 */
final class Array_Reverse_Return_Type_Provider implements Function_Return_Type_Provider_Interface
{
    /**
     * @return array<lowercase-string>
     */
    #[Override]
    public static function get_function_ids(): array
    {
        return ['array_reverse'];
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
        $first_arg_type = null;
        $first_arg_array = $first_arg && ($first_arg_type = $statements_source->node_data->get_type($first_arg)) && $first_arg_type->has_type('array') && $first_arg_type->is_array() && ($array_atomic_type = $first_arg_type->get_array()) && ($array_atomic_type instanceof T_Array || $array_atomic_type instanceof T_Keyed_Array) ? $array_atomic_type : null;
        if (!$first_arg_array || !$first_arg_type) {
            return Type::get_array();
        }
        if ($first_arg_array instanceof T_Array) {
            return $first_arg_type;
        }
        if ($first_arg_array->is_list) {
            $second_arg = $call_args[1]->value ?? null;
            if (!$second_arg || ($second_arg_type = $statements_source->node_data->get_type($second_arg)) && $second_arg_type->is_false()) {
                if ($first_arg_array->fallback_params) {
                    return $first_arg_array->is_non_empty() ? Type::get_non_empty_list($first_arg_array->get_generic_value_type()) : Type::get_list($first_arg_array->get_generic_value_type());
                }
                $reversed_array_items = [];
                $num_undefined = 0;
                $i = 0;
                foreach (array_reverse($first_arg_array->properties) as $array_item_type) {
                    $reversed_array_items[] = $array_item_type;
                    /** @var int<0,max> $j */
                    $j = $i - $num_undefined;
                    for (; $j < $i; ++$j) {
                        $reversed_array_items[$j] = Type_Combiner::combine([...array_values($reversed_array_items[$j]->get_atomic_types()), ...array_values($array_item_type->get_atomic_types())]);
                    }
                    if ($array_item_type->possibly_undefined) {
                        ++$num_undefined;
                    }
                    ++$i;
                }
                $max_len = count($reversed_array_items);
                /** @var int<0,max> $i */
                $i = $max_len - $num_undefined;
                for (; $i < $max_len; ++$i) {
                    $reversed_array_items[$i] = $reversed_array_items[$i]->set_possibly_undefined(true);
                }
                return new Union([$first_arg_array->set_properties($reversed_array_items)]);
            }
            return new Union([new T_Keyed_Array($first_arg_array->properties, null, $first_arg_array->fallback_params, false)]);
        }
        return new Union([$first_arg_array->get_generic_array_type()]);
    }
}