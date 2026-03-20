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
use Psalm\Type\Atomic\T_Non_Empty_Array;
use Psalm\Type\Atomic\T_Null;
use Psalm\Type\Union;
/**
 * @internal
 */
final class Array_Pop_Return_Type_Provider implements Function_Return_Type_Provider_Interface
{
    /**
     * @return array<lowercase-string>
     */
    #[Override]
    public static function get_function_ids(): array
    {
        return ['array_pop', 'array_shift'];
    }
    #[Override]
    public static function get_function_return_type(Function_Return_Type_Provider_Event $event): Union
    {
        $statements_source = $event->get_statements_source();
        $call_args = $event->get_call_args();
        $function_id = $event->get_function_id();
        if (!$statements_source instanceof Statements_Analyzer) {
            return Type::get_mixed();
        }
        $first_arg = $call_args[0]->value ?? null;
        $first_arg_array = $first_arg && ($first_arg_type = $statements_source->node_data->get_type($first_arg)) && $first_arg_type->has_type('array') && !$first_arg_type->has_mixed() && ($array_atomic_type = $first_arg_type->get_array()) && ($array_atomic_type instanceof T_Array || $array_atomic_type instanceof T_Keyed_Array) ? $array_atomic_type : null;
        if (!$first_arg_array) {
            return Type::get_mixed();
        }
        $nullable = false;
        if ($first_arg_array instanceof T_Array) {
            $value_type = $first_arg_array->type_params[1];
            if ($first_arg_array->is_empty_array()) {
                return Type::get_null();
            }
            if (!$first_arg_array instanceof T_Non_Empty_Array) {
                $nullable = true;
            }
        } else if ($function_id === 'array_shift' && $first_arg_array->is_list && isset($first_arg_array->properties[0])) {
            $value_type = $first_arg_array->properties[0];
            if ($value_type->possibly_undefined) {
                $value_type = $value_type->set_possibly_undefined(false);
                $nullable = true;
            }
        } else {
            $value_type = $first_arg_array->get_generic_value_type();
            if (!$first_arg_array->is_non_empty()) {
                $nullable = true;
            }
        }
        if ($nullable) {
            $value_type = $value_type->get_builder()->add_type(new T_Null());
            $codebase = $statements_source->get_codebase();
            if ($codebase->config->ignore_internal_nullable_issues) {
                $value_type->ignore_nullable_issues = true;
            }
            $value_type = $value_type->freeze();
        }
        return $value_type;
    }
}