<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider\Return_Type_Provider;

use Override;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Plugin\Event_Handler\Event\Function_Return_Type_Provider_Event;
use Psalm\Plugin\Event_Handler\Function_Return_Type_Provider_Interface;
use Psalm\Type;
use function array_values;
use function count;
use function round;
use const PHP_ROUND_HALF_UP;
/**
 * @internal
 */
final class Round_Return_Type_Provider implements Function_Return_Type_Provider_Interface
{
    /**
     * @return array<lowercase-string>
     */
    #[Override]
    public static function get_function_ids(): array
    {
        return ['round'];
    }
    #[Override]
    public static function get_function_return_type(Function_Return_Type_Provider_Event $event): ?Type\Union
    {
        $call_args = $event->get_call_args();
        if (count($call_args) === 0) {
            return null;
        }
        $statements_source = $event->get_statements_source();
        $node_type_provider = $statements_source->get_node_type_provider();
        $num_arg = $node_type_provider->get_type($call_args[0]->value);
        $precision_val = 0;
        if ($statements_source instanceof Statements_Analyzer && count($call_args) > 1) {
            $type = $statements_source->node_data->get_type($call_args[1]->value);
            if ($type !== null && $type->is_single()) {
                $atomic_type = array_values($type->get_atomic_types())[0];
                if ($atomic_type instanceof Type\Atomic\T_Literal_Int) {
                    $precision_val = $atomic_type->value;
                }
            }
        }
        $mode_val = PHP_ROUND_HALF_UP;
        if ($statements_source instanceof Statements_Analyzer && count($call_args) > 2) {
            $type = $statements_source->node_data->get_type($call_args[2]->value);
            if ($type !== null && $type->is_single()) {
                $atomic_type = array_values($type->get_atomic_types())[0];
                if ($atomic_type instanceof Type\Atomic\T_Literal_Int) {
                    /** @var positive-int|0 $mode_val */
                    $mode_val = $atomic_type->value;
                }
            }
        }
        if ($num_arg !== null && $num_arg->is_single()) {
            $num_type = array_values($num_arg->get_atomic_types())[0];
            if ($num_type instanceof Type\Atomic\T_Literal_Float || $num_type instanceof Type\Atomic\T_Literal_Int) {
                $rounded_val = round($num_type->value, $precision_val, $mode_val);
                return new Type\Union([new Type\Atomic\T_Literal_Float($rounded_val)]);
            }
        }
        return new Type\Union([new Type\Atomic\T_Float()]);
    }
}