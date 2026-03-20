<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider\Return_Type_Provider;

use Override;
use Psalm\Internal\Analyzer\Statements\Expression\Include_Analyzer;
use Psalm\Plugin\Event_Handler\Event\Function_Return_Type_Provider_Event;
use Psalm\Plugin\Event_Handler\Function_Return_Type_Provider_Interface;
use Psalm\Type;
use Psalm\Type\Atomic\T_Literal_Int;
use Psalm\Type\Union;
use function array_values;
use function count;
use function dirname;
/**
 * @internal
 */
final class Dirname_Return_Type_Provider implements Function_Return_Type_Provider_Interface
{
    /**
     * @return array<lowercase-string>
     */
    #[Override]
    public static function get_function_ids(): array
    {
        return ['dirname'];
    }
    #[Override]
    public static function get_function_return_type(Function_Return_Type_Provider_Event $event): ?Union
    {
        $call_args = $event->get_call_args();
        if (count($call_args) === 0) {
            return null;
        }
        $statements_source = $event->get_statements_source();
        $node_type_provider = $statements_source->get_node_type_provider();
        $union = $node_type_provider->get_type($call_args[0]->value);
        $generic = false;
        if ($union !== null) {
            foreach ($union->get_atomic_types() as $atomic) {
                if ($atomic instanceof Type\Atomic\T_Non_Falsy_String) {
                    continue;
                }
                if ($atomic instanceof Type\Atomic\T_Literal_String) {
                    if ($atomic->value === '') {
                        $generic = true;
                        break;
                    }
                    // 0 will be non-falsy too (.)
                    continue;
                }
                if ($atomic instanceof Type\Atomic\T_Non_Empty_String) {
                    continue;
                }
                if ($atomic instanceof Type\Atomic\T_Empty_Numeric) {
                    continue;
                }
                // generic string is the only other possible case of empty string
                // which would result in a generic string
                $generic = true;
                break;
            }
        }
        $fallback_type = Type::get_non_falsy_string();
        if ($union === null || $generic) {
            $fallback_type = Type::get_string();
        }
        $dir_level = 1;
        if (isset($call_args[1])) {
            $type = $node_type_provider->get_type($call_args[1]->value);
            if ($type !== null && $type->is_single()) {
                $atomic_type = array_values($type->get_atomic_types())[0];
                if ($atomic_type instanceof T_Literal_Int && $atomic_type->value > 0) {
                    $dir_level = $atomic_type->value;
                } else {
                    return $fallback_type;
                }
            }
        }
        $evaled_path = Include_Analyzer::get_path_to($call_args[0]->value, null, null, $statements_source->get_file_name(), $statements_source->get_codebase()->config);
        if ($evaled_path === null) {
            return $fallback_type;
        }
        $path_to_file = dirname($evaled_path, $dir_level);
        return Type::get_string($path_to_file);
    }
}