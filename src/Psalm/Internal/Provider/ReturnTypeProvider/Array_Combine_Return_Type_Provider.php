<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider\Return_Type_Provider;

use Override;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Issue\Invalid_Argument;
use Psalm\Issue_Buffer;
use Psalm\Plugin\Event_Handler\Event\Function_Return_Type_Provider_Event;
use Psalm\Plugin\Event_Handler\Function_Return_Type_Provider_Interface;
use Psalm\Type;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Union;
use function array_combine;
use function count;
/**
 * @internal
 */
final class Array_Combine_Return_Type_Provider implements Function_Return_Type_Provider_Interface
{
    /**
     * @return array<lowercase-string>
     */
    #[Override]
    public static function get_function_ids(): array
    {
        return ['array_combine'];
    }
    #[Override]
    public static function get_function_return_type(Function_Return_Type_Provider_Event $event): ?Union
    {
        $statements_source = $event->get_statements_source();
        $call_args = $event->get_call_args();
        if (!$statements_source instanceof Statements_Analyzer || count($call_args) < 2) {
            return Type::get_never();
        }
        if (!$keys_type = $statements_source->node_data->get_type($call_args[0]->value)) {
            return null;
        }
        if (!$keys_type->is_array()) {
            return null;
        }
        $keys = $keys_type->get_array();
        if ($keys instanceof T_Array && $keys->is_empty_array()) {
            $keys = [];
        } elseif (!$keys instanceof T_Keyed_Array || $keys->fallback_params) {
            return null;
        } else {
            $keys = $keys->properties;
        }
        if (!$values_type = $statements_source->node_data->get_type($call_args[1]->value)) {
            return null;
        }
        if (!$values_type->is_array()) {
            return null;
        }
        $values = $values_type->get_array();
        if ($values instanceof T_Array && $values->is_empty_array()) {
            $values = [];
        } elseif (!$values instanceof T_Keyed_Array || $values->fallback_params) {
            return null;
        } else {
            $values = $values->properties;
        }
        $keys_array = [];
        $is_list = true;
        $prev_key = -1;
        foreach ($keys as $key) {
            if ($key->possibly_undefined) {
                return null;
            }
            if ($key->is_single_int_literal()) {
                $key = $key->get_single_int_literal()->value;
                $keys_array[] = $key;
                if ($is_list && $key - 1 !== $prev_key) {
                    $is_list = false;
                }
                $prev_key = $key;
            } elseif ($key->is_single_string_literal()) {
                $keys_array[] = $key->get_single_string_literal()->value;
                $is_list = false;
            } else {
                return null;
            }
        }
        foreach ($values as $value) {
            if ($value->possibly_undefined) {
                return null;
            }
        }
        if (count($keys_array) !== count($values)) {
            Issue_Buffer::maybe_add(new Invalid_Argument('The keys array ' . $keys_type->get_id() . ' must have exactly the same ' . 'number of elements as the values array ' . $values_type->get_id(), $event->get_code_location(), 'array_combine'), $statements_source->get_suppressed_issues());
            return $statements_source->get_codebase()->analysis_php_version_id >= 80000 ? Type::get_never() : Type::get_false();
        }
        $result = array_combine($keys_array, $values);
        if (!$result) {
            return Type::get_empty_array();
        }
        return new Union([new T_Keyed_Array($result, null, null, $is_list)]);
    }
}