<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider\Return_Type_Provider;

use Override;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Plugin\Event_Handler\Event\Function_Return_Type_Provider_Event;
use Psalm\Plugin\Event_Handler\Function_Return_Type_Provider_Interface;
use Psalm\Type;
use Psalm\Type\Atomic\T_Null;
use Psalm\Type\Atomic\T_String;
use Psalm\Type\Union;
/**
 * @internal
 */
final class First_Arg_String_Return_Type_Provider implements Function_Return_Type_Provider_Interface
{
    /**
     * @return array<lowercase-string>
     */
    #[Override]
    public static function get_function_ids(): array
    {
        return ['crypt'];
    }
    #[Override]
    public static function get_function_return_type(Function_Return_Type_Provider_Event $event): \Psalm\Type\Union
    {
        $statements_source = $event->get_statements_source();
        $call_args = $event->get_call_args();
        if (!$statements_source instanceof Statements_Analyzer || !$call_args) {
            return Type::get_mixed();
        }
        if (($first_arg_type = $statements_source->node_data->get_type($call_args[0]->value)) && $first_arg_type->is_string()) {
            return new Union([new T_String()]);
        }
        return new Union([new T_String(), new T_Null()], ['ignore_nullable_issues' => true]);
    }
}