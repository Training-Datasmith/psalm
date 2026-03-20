<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider\Return_Type_Provider;

use Override;
use Psalm\Plugin\Event_Handler\Event\Function_Return_Type_Provider_Event;
use Psalm\Plugin\Event_Handler\Function_Return_Type_Provider_Interface;
use Psalm\Type;
use Psalm\Type\Atomic\T_Bool;
use Psalm\Type\Atomic\T_False;
use Psalm\Type\Atomic\T_Never;
use Psalm\Type\Atomic\T_Null;
use Psalm\Type\Atomic\T_String;
use Psalm\Type\Atomic\T_True;
use Psalm\Type\Union;
use function assert;
use function count;
/**
 * @internal
 */
final class Mb_Internal_Encoding_Return_Type_Provider implements Function_Return_Type_Provider_Interface
{
    /**
     * @return array<lowercase-string>
     */
    #[Override]
    public static function get_function_ids(): array
    {
        return ['mb_internal_encoding'];
    }
    #[Override]
    public static function get_function_return_type(Function_Return_Type_Provider_Event $event): \Psalm\Type\Union
    {
        $call_args = $event->get_call_args();
        if (count($call_args) === 0) {
            return Type::get_string();
        }
        $statements_source = $event->get_statements_source();
        $node_type_provider = $statements_source->get_node_type_provider();
        $codebase = $statements_source->get_codebase();
        $first_arg_type = $node_type_provider->get_type($call_args[0]->value);
        if ($first_arg_type === null) {
            if ($codebase->analysis_php_version_id >= 80000) {
                return new Union([new T_String(), new T_True()]);
            }
            return new Union([new T_String(), new T_Bool()]);
        }
        $has_stringable = false;
        $has_tostring = false;
        $has_string = false;
        $has_null = false;
        $has_unknown = false;
        foreach ($first_arg_type->get_atomic_types() as $atomic_type) {
            if ($atomic_type instanceof Type\Atomic\T_Named_Object && $codebase->classlikes->class_implements($atomic_type->value, 'Stringable')) {
                $has_stringable = true;
                continue;
            }
            if ($atomic_type instanceof Type\Atomic\T_Object_With_Properties && isset($atomic_type->methods['__tostring'])) {
                $has_tostring = true;
                continue;
            }
            if ($atomic_type instanceof T_String) {
                $has_string = true;
                continue;
            }
            if ($atomic_type instanceof T_Null) {
                $has_null = true;
                continue;
            }
            $has_unknown = true;
        }
        $list_return_atomics = [];
        if ($has_string || $has_stringable || $has_tostring) {
            if ($codebase->analysis_php_version_id >= 80000) {
                $list_return_atomics[] = new T_True();
            } else {
                $list_return_atomics[] = new T_Bool();
            }
        }
        if ($has_null) {
            if ($codebase->analysis_php_version_id >= 80000) {
                $list_return_atomics[] = new T_String();
            } else {
                $list_return_atomics[] = new T_False();
            }
        }
        if ($has_unknown) {
            if ($codebase->analysis_php_version_id >= 80000) {
                $list_return_atomics[] = new T_Never();
            } else {
                $list_return_atomics[] = new T_Null();
            }
        }
        assert($list_return_atomics !== []);
        return new Union($list_return_atomics);
    }
}