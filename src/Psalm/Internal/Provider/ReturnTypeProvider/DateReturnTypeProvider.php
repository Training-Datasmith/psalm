<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider\Return_Type_Provider;

use Override;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Plugin\Event_Handler\Event\Function_Return_Type_Provider_Event;
use Psalm\Plugin\Event_Handler\Function_Return_Type_Provider_Interface;
use Psalm\Type;
use Psalm\Type\Atomic\T_Literal_Int;
use Psalm\Type\Atomic\T_Literal_String;
use Psalm\Type\Union;
use function array_values;
use function date;
use function is_numeric;
/**
 * @internal
 */
final class Date_Return_Type_Provider implements Function_Return_Type_Provider_Interface
{
    /**
     * @return array<lowercase-string>
     */
    #[Override]
    public static function get_function_ids(): array
    {
        return ['date', 'gmdate'];
    }
    #[Override]
    public static function get_function_return_type(Function_Return_Type_Provider_Event $event): ?Union
    {
        $source = $event->get_statements_source();
        if (!$source instanceof Statements_Analyzer) {
            return null;
        }
        $call_args = $event->get_call_args();
        $format_type = Type::get_string();
        if (isset($call_args[0])) {
            $type = $source->node_data->get_type($call_args[0]->value);
            if ($type !== null && $type->is_single_string_literal() && is_numeric(date($type->get_single_string_literal()->value))) {
                $format_type = Type::get_numeric_string();
            }
        }
        if (!isset($call_args[1])) {
            return $format_type;
        }
        $type = $source->node_data->get_type($call_args[1]->value);
        if ($type !== null && $type->is_single()) {
            $atomic_type = array_values($type->get_atomic_types())[0];
            if ($atomic_type instanceof Type\Atomic\T_Numeric || $atomic_type instanceof Type\Atomic\T_Int || $atomic_type instanceof T_Literal_Int || $atomic_type instanceof T_Literal_String && is_numeric($atomic_type->value)) {
                return $format_type;
            }
        }
        return $format_type;
    }
}