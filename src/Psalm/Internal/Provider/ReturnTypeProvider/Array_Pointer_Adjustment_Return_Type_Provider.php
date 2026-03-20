<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider\Return_Type_Provider;

use Override;
use Psalm\Internal\Analyzer\Statements\Expression\Fetch\Array_Fetch_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Plugin\Event_Handler\Event\Function_Return_Type_Provider_Event;
use Psalm\Plugin\Event_Handler\Function_Return_Type_Provider_Interface;
use Psalm\Type;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_False;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Atomic\T_Non_Empty_Array;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Union;
use UnexpectedValueException;
use function array_merge;
use function array_shift;
use function in_array;
/**
 * @internal
 */
final class Array_Pointer_Adjustment_Return_Type_Provider implements Function_Return_Type_Provider_Interface
{
    /**
     * These functions are already handled by the CoreGenericFunctions stub
     */
    public const IGNORE_FUNCTION_IDS_FOR_FALSE_RETURN_TYPE = ['reset', 'end', 'current'];
    /**
     * @return array<lowercase-string>
     */
    #[Override]
    public static function get_function_ids(): array
    {
        return ['current', 'next', 'prev', 'reset', 'end'];
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
        if (!$first_arg) {
            return Type::get_mixed();
        }
        $first_arg_type = $statements_source->node_data->get_type($first_arg);
        if (!$first_arg_type) {
            return Type::get_mixed();
        }
        $atomic_types = $first_arg_type->get_atomic_types();
        $value_type = null;
        $definitely_has_items = false;
        while ($atomic_type = array_shift($atomic_types)) {
            if ($atomic_type instanceof T_Template_Param) {
                $atomic_types = array_merge($atomic_types, $atomic_type->as->get_atomic_types());
                continue;
            }
            if ($atomic_type instanceof T_Array) {
                $value_type = $atomic_type->type_params[1];
                $definitely_has_items = $atomic_type instanceof T_Non_Empty_Array;
            } elseif ($atomic_type instanceof T_Keyed_Array) {
                $value_type = $atomic_type->get_generic_value_type();
                $definitely_has_items = $atomic_type->get_generic_array_type() instanceof T_Non_Empty_Array;
            } else {
                return Type::get_mixed();
            }
        }
        if (!$value_type) {
            throw new UnexpectedValueException('This should never happen');
        }
        if ($value_type->is_never()) {
            $value_type = Type::get_false();
        } elseif (!$definitely_has_items || self::is_function_already_handled_by_stub($function_id)) {
            $value_type = $value_type->get_builder()->add_type(new T_False());
            $codebase = $statements_source->get_codebase();
            if ($codebase->config->ignore_internal_falsable_issues) {
                $value_type->ignore_falsable_issues = true;
            }
            $value_type = $value_type->freeze();
        }
        $temp = Type::get_mixed();
        Array_Fetch_Analyzer::taint_array_fetch($statements_source, $first_arg, null, $value_type, $temp);
        return $value_type;
    }
    private static function is_function_already_handled_by_stub(string $function_id): bool
    {
        return !in_array($function_id, self::IGNORE_FUNCTION_IDS_FOR_FALSE_RETURN_TYPE, true);
    }
}