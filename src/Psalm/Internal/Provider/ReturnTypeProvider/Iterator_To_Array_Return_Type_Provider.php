<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider\Return_Type_Provider;

use Override;
use Psalm\Internal\Analyzer\Statements\Block\Foreach_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Codebase\Internal_Call_Map_Handler;
use Psalm\Internal\Type\Comparator\Atomic_Type_Comparator;
use Psalm\Plugin\Event_Handler\Event\Function_Return_Type_Provider_Event;
use Psalm\Plugin\Event_Handler\Function_Return_Type_Provider_Interface;
use Psalm\Type;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Iterable;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Union;
use function array_merge;
use function array_shift;
use function assert;
/**
 * @internal
 */
final class Iterator_To_Array_Return_Type_Provider implements Function_Return_Type_Provider_Interface
{
    /**
     * @return array<lowercase-string>
     */
    #[Override]
    public static function get_function_ids(): array
    {
        return ['iterator_to_array'];
    }
    #[Override]
    public static function get_function_return_type(Function_Return_Type_Provider_Event $event): Union
    {
        $statements_source = $event->get_statements_source();
        $call_args = $event->get_call_args();
        $function_id = $event->get_function_id();
        $context = $event->get_context();
        if (!$statements_source instanceof Statements_Analyzer || !$call_args) {
            return Type::get_mixed();
        }
        if ($first_arg_type = $statements_source->node_data->get_type($call_args[0]->value)) {
            $key_type = null;
            $value_type = null;
            $codebase = $statements_source->get_codebase();
            $atomic_types = $first_arg_type->get_atomic_types();
            while ($call_arg_atomic_type = array_shift($atomic_types)) {
                if ($call_arg_atomic_type instanceof T_Template_Param) {
                    $atomic_types = array_merge($atomic_types, $call_arg_atomic_type->as->get_atomic_types());
                    continue;
                }
                if ($call_arg_atomic_type instanceof T_Iterable) {
                    $key_type = $call_arg_atomic_type->type_params[0];
                    $value_type = $call_arg_atomic_type->type_params[1];
                    continue;
                }
                if ($call_arg_atomic_type instanceof T_Named_Object && Atomic_Type_Comparator::is_contained_by($codebase, $call_arg_atomic_type, new T_Iterable([Type::get_mixed(), Type::get_mixed()]))) {
                    $has_valid_iterator = true;
                    Foreach_Analyzer::handle_iterable($statements_source, $call_arg_atomic_type, $call_args[0]->value, $codebase, $context, $key_type, $value_type, $has_valid_iterator);
                }
            }
            if ($value_type) {
                $second_arg_type = isset($call_args[1]) ? $statements_source->node_data->get_type($call_args[1]->value) : null;
                if ($second_arg_type && (string) $second_arg_type === 'false') {
                    return Type::get_list($value_type);
                }
                $key_type = $key_type && (!isset($call_args[1]) || $second_arg_type && (string) $second_arg_type === 'true') ? $key_type : Type::get_array_key();
                if ($key_type->has_mixed()) {
                    $key_type = Type::get_array_key();
                }
                if ($key_type->is_single() && $key_type->has_template()) {
                    $template_types = $key_type->get_template_types();
                    $template_type = array_shift($template_types);
                    assert($template_type !== null);
                    if ($template_type->as->has_mixed()) {
                        $template_type = $template_type->replace_as(Type::get_array_key());
                        $key_type = new Union([$template_type]);
                    }
                }
                return new Union([new T_Array([$key_type, $value_type])]);
            }
        }
        $callmap_callables = Internal_Call_Map_Handler::get_callables_from_call_map($function_id);
        assert($callmap_callables && $callmap_callables[0]->return_type);
        return $callmap_callables[0]->return_type;
    }
}