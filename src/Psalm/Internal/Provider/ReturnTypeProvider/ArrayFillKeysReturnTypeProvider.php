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
use function count;
/**
 * @internal
 */
final class Array_Fill_Keys_Return_Type_Provider implements Function_Return_Type_Provider_Interface
{
    /**
     * @return array<lowercase-string>
     */
    #[Override]
    public static function get_function_ids(): array
    {
        return ['array_fill_keys'];
    }
    #[Override]
    public static function get_function_return_type(Function_Return_Type_Provider_Event $event): ?Union
    {
        $statements_source = $event->get_statements_source();
        $call_args = $event->get_call_args();
        if (!$statements_source instanceof Statements_Analyzer) {
            return Type::get_mixed();
        }
        if (count($call_args) !== 2) {
            return Type::get_never();
        }
        $first_arg_type = isset($call_args[0]) ? $statements_source->node_data->get_type($call_args[0]->value) : null;
        $second_arg_type = isset($call_args[1]) ? $statements_source->node_data->get_type($call_args[1]->value) : null;
        if ($first_arg_type && $first_arg_type->is_array() && $second_arg_type) {
            $array = $first_arg_type->get_array();
            if ($array instanceof T_Array && $array->is_empty_array()) {
                return $first_arg_type;
            }
            if ($array instanceof T_Keyed_Array && !$array->fallback_params) {
                $is_list = $array->is_list;
                $array = $array->properties;
            } else {
                return null;
            }
            $result = [];
            $prev_key = -1;
            $had_possibly_undefined = false;
            foreach ($array as $key_k) {
                if ($had_possibly_undefined && !$key_k->possibly_undefined) {
                    $is_list = false;
                }
                $had_possibly_undefined = $had_possibly_undefined || $key_k->possibly_undefined;
                if ($key_k->is_single_int_literal()) {
                    $key = $key_k->get_single_int_literal()->value;
                    if ($prev_key !== $key - 1) {
                        $is_list = false;
                    }
                    $prev_key = $key;
                } elseif ($key_k->is_single_string_literal()) {
                    $key = $key_k->get_single_string_literal()->value;
                    $is_list = false;
                } else {
                    return null;
                }
                $result[$key] = $second_arg_type->set_possibly_undefined($key_k->possibly_undefined);
            }
            return new Union([new T_Keyed_Array($result, null, null, $is_list)]);
        }
        return null;
    }
}