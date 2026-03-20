<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider\Return_Type_Provider;

use Override;
use Psalm\Internal\Type\Type_Combiner;
use Psalm\Plugin\Event_Handler\Event\Function_Return_Type_Provider_Event;
use Psalm\Plugin\Event_Handler\Function_Return_Type_Provider_Interface;
use Psalm\Type;
use Psalm\Type\Atomic\T_Bool;
use Psalm\Type\Atomic\T_False;
use Psalm\Type\Atomic\T_Literal_Int;
use Psalm\Type\Atomic\T_Never;
use Psalm\Type\Atomic\T_True;
use Psalm\Type\Union;
use function in_array;
use const E_USER_DEPRECATED;
use const E_USER_ERROR;
use const E_USER_NOTICE;
use const E_USER_WARNING;
/**
 * @internal
 */
final class Trigger_Error_Return_Type_Provider implements Function_Return_Type_Provider_Interface
{
    /**
     * @return array<lowercase-string>
     */
    #[Override]
    public static function get_function_ids(): array
    {
        return ['trigger_error'];
    }
    #[Override]
    public static function get_function_return_type(Function_Return_Type_Provider_Event $event): \Psalm\Type\Union
    {
        $codebase = $event->get_statements_source()->get_codebase();
        $config = $codebase->config;
        if ($config->trigger_error_exits === 'always') {
            return Type::get_never();
        }
        if ($config->trigger_error_exits === 'never') {
            return new Union([new T_True()]);
        }
        //default behaviour
        $call_args = $event->get_call_args();
        $statements_source = $event->get_statements_source();
        if (isset($call_args[1]) && $array_arg_type = $statements_source->get_node_type_provider()->get_type($call_args[1]->value)) {
            $return_types = [];
            foreach ($array_arg_type->get_atomic_types() as $atomic_type) {
                if ($atomic_type instanceof T_Literal_Int) {
                    if (in_array($atomic_type->value, [E_USER_WARNING, E_USER_DEPRECATED, E_USER_NOTICE], true)) {
                        $return_types[] = new T_True();
                    } elseif ($atomic_type->value === E_USER_ERROR) {
                        $return_types[] = new T_Never();
                    } else {
                        // not recognized int literal. return false before PHP8, fatal error since
                        $return_types[] = new T_False();
                    }
                } else {
                    $return_types[] = new T_Bool();
                }
            }
            return Type_Combiner::combine($return_types, $codebase);
        }
        //default value is E_USER_NOTICE, so return true
        return new Union([new T_True()]);
    }
}