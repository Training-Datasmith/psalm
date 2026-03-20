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
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Union;
use UnexpectedValueException;
use function array_merge;
use function array_shift;
/**
 * @internal
 */
final class Array_Slice_Return_Type_Provider implements Function_Return_Type_Provider_Interface
{
    /**
     * @return array<lowercase-string>
     */
    #[Override]
    public static function get_function_ids(): array
    {
        return ['array_slice'];
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
        if (!$first_arg) {
            return Type::get_array();
        }
        $first_arg_type = $statements_source->node_data->get_type($first_arg);
        if (!$first_arg_type) {
            return Type::get_array();
        }
        $atomic_types = $first_arg_type->get_atomic_types();
        $return_atomic_type = null;
        while ($atomic_type = array_shift($atomic_types)) {
            if ($atomic_type instanceof T_Template_Param) {
                $atomic_types = array_merge($atomic_types, $atomic_type->as->get_atomic_types());
                continue;
            }
            if ($atomic_type instanceof T_Keyed_Array) {
                $atomic_type = $atomic_type->get_generic_array_type();
            }
            if ($atomic_type instanceof T_Array) {
                $return_atomic_type = new T_Array($atomic_type->type_params);
                continue;
            }
            return Type::get_array();
        }
        if (!$return_atomic_type) {
            throw new UnexpectedValueException('This should never happen');
        }
        $dont_preserve_int_keys = !isset($call_args[3]->value) || ($third_arg_type = $statements_source->node_data->get_type($call_args[3]->value)) && (string) $third_arg_type === 'false';
        if ($dont_preserve_int_keys && $return_atomic_type->type_params[0]->is_int()) {
            $return_atomic_type = Type::get_list_atomic($return_atomic_type->type_params[1]);
        }
        return new Union([$return_atomic_type]);
    }
}