<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider\Return_Type_Provider;

use Override;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Plugin\Event_Handler\Event\Function_Return_Type_Provider_Event;
use Psalm\Plugin\Event_Handler\Function_Return_Type_Provider_Interface;
use Psalm\Type;
use Psalm\Type\Atomic\T_False;
use Psalm\Type\Atomic\T_Int;
use Psalm\Type\Union;
/**
 * @internal
 */
final class Mktime_Return_Type_Provider implements Function_Return_Type_Provider_Interface
{
    /**
     * @return array<lowercase-string>
     */
    #[Override]
    public static function get_function_ids(): array
    {
        return ['mktime'];
    }
    #[Override]
    public static function get_function_return_type(Function_Return_Type_Provider_Event $event): Union
    {
        $statements_source = $event->get_statements_source();
        $call_args = $event->get_call_args();
        if (!$statements_source instanceof Statements_Analyzer) {
            return Type::get_mixed();
        }
        foreach ($call_args as $call_arg) {
            if (!($call_arg_type = $statements_source->node_data->get_type($call_arg->value)) || !$call_arg_type->is_int()) {
                $codebase = $statements_source->get_codebase();
                return new Union([new T_Int(), new T_False()], ['ignore_falsable_issues' => $codebase->config->ignore_internal_falsable_issues]);
            }
        }
        return Type::get_int();
    }
}