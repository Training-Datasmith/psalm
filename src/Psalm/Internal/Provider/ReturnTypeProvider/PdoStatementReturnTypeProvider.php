<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider\Return_Type_Provider;

use Override;
use Psalm\Config;
use Psalm\Plugin\Event_Handler\Event\Method_Return_Type_Provider_Event;
use Psalm\Plugin\Event_Handler\Method_Return_Type_Provider_Interface;
use Psalm\Type;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_False;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Null;
use Psalm\Type\Atomic\T_Object;
use Psalm\Type\Atomic\T_Scalar;
use Psalm\Type\Union;
/**
 * @internal
 */
final class Pdo_Statement_Return_Type_Provider implements Method_Return_Type_Provider_Interface
{
    #[Override]
    public static function get_class_like_names(): array
    {
        return ['PDOStatement'];
    }
    #[Override]
    public static function get_method_return_type(Method_Return_Type_Provider_Event $event): ?Union
    {
        $config = Config::get_instance();
        $method_name_lowercase = $event->get_method_name_lowercase();
        if (!$config->php_extensions["pdo"]) {
            return null;
        }
        if ($method_name_lowercase === 'fetch') {
            return self::handle_fetch($event);
        }
        if ($method_name_lowercase === 'fetchall') {
            return self::handle_fetch_all($event);
        }
        return null;
    }
    private static function handle_fetch(Method_Return_Type_Provider_Event $event): ?Union
    {
        $source = $event->get_source();
        $call_args = $event->get_call_args();
        $fetch_mode = 0;
        foreach ($call_args as $call_arg) {
            $arg_name = $call_arg->name;
            if (!isset($arg_name) || $arg_name->name === "mode") {
                $arg_type = $source->get_node_type_provider()->get_type($call_arg->value);
                if (isset($arg_type) && $arg_type->is_single_int_literal()) {
                    $fetch_mode = $arg_type->get_single_int_literal()->value;
                }
                break;
            }
        }
        return match ($fetch_mode) {
            2 => new Union([new T_Array([Type::get_string(), new Union([new T_Scalar(), new T_Null()])]), new T_False()]),
            4 => new Union([new T_Array([Type::get_array_key(), new Union([new T_Scalar(), new T_Null()])]), new T_False()]),
            6 => Type::get_bool(),
            7 => new Union([new T_Scalar(), new T_Null(), new T_False()]),
            8 => new Union([new T_Object(), new T_False()]),
            1 => new Union([new T_Object(), new T_False()]),
            11 => new Union([new T_Array([Type::get_string(), new Union([new T_Scalar(), new T_Null(), Type::get_list_atomic(new Union([new T_Scalar(), new T_Null()]))])]), new T_False()]),
            12 => new Union([new T_Array([Type::get_array_key(), new Union([new T_Scalar(), new T_Null()])])]),
            3 => new Union([Type::get_list_atomic(new Union([new T_Scalar(), new T_Null()])), new T_False()]),
            5 => new Union([new T_Named_Object('stdClass'), new T_False()]),
            default => null,
        };
    }
    private static function handle_fetch_all(Method_Return_Type_Provider_Event $event): ?Union
    {
        $source = $event->get_source();
        $call_args = $event->get_call_args();
        $fetch_mode = 0;
        if (isset($call_args[0]) && ($first_arg_type = $source->get_node_type_provider()->get_type($call_args[0]->value)) && $first_arg_type->is_single_int_literal()) {
            $fetch_mode = $first_arg_type->get_single_int_literal()->value;
        }
        $fetch_class_name = null;
        if (isset($call_args[1]) && ($second_arg_type = $source->get_node_type_provider()->get_type($call_args[1]->value)) && $second_arg_type->is_single_string_literal()) {
            $fetch_class_name = $second_arg_type->get_single_string_literal()->value;
        }
        return match ($fetch_mode) {
            2 => new Union([Type::get_list_atomic(new Union([new T_Array([Type::get_string(), new Union([new T_Scalar(), new T_Null()])])]))]),
            4 => new Union([Type::get_list_atomic(new Union([new T_Array([Type::get_array_key(), new Union([new T_Scalar(), new T_Null()])])]))]),
            6 => new Union([Type::get_list_atomic(Type::get_bool())]),
            7 => new Union([Type::get_list_atomic(new Union([new T_Scalar(), new T_Null()]))]),
            8 => new Union([Type::get_list_atomic(new Union([$fetch_class_name ? new T_Named_Object($fetch_class_name) : new T_Object()]))]),
            11 => new Union([Type::get_list_atomic(new Union([new T_Array([Type::get_string(), new Union([new T_Scalar(), new T_Null(), Type::get_list_atomic(new Union([new T_Scalar(), new T_Null()]))])])]))]),
            12 => new Union([new T_Array([Type::get_array_key(), new Union([new T_Scalar(), new T_Null()])])]),
            3 => new Union([Type::get_list_atomic(new Union([Type::get_list_atomic(new Union([new T_Scalar(), new T_Null()]))]))]),
            5 => new Union([Type::get_list_atomic(new Union([new T_Named_Object('stdClass')]))]),
            default => null,
        };
    }
}