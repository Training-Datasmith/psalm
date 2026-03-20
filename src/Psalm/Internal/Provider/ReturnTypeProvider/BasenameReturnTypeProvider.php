<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider\Return_Type_Provider;

use Override;
use Psalm\Internal\Analyzer\Statements\Expression\Include_Analyzer;
use Psalm\Plugin\Event_Handler\Event\Function_Return_Type_Provider_Event;
use Psalm\Plugin\Event_Handler\Function_Return_Type_Provider_Interface;
use Psalm\Type;
use Psalm\Type\Union;
use function basename;
use function count;
/**
 * @internal
 */
final class Basename_Return_Type_Provider implements Function_Return_Type_Provider_Interface
{
    /**
     * @return array<lowercase-string>
     */
    #[Override]
    public static function get_function_ids(): array
    {
        return ['basename'];
    }
    #[Override]
    public static function get_function_return_type(Function_Return_Type_Provider_Event $event): ?Union
    {
        $call_args = $event->get_call_args();
        if (count($call_args) === 0) {
            return null;
        }
        $statements_source = $event->get_statements_source();
        $evaled_path = Include_Analyzer::get_path_to($call_args[0]->value, null, null, $statements_source->get_file_name(), $statements_source->get_codebase()->config);
        if ($evaled_path === null) {
            $union = $statements_source->get_node_type_provider()->get_type($call_args[0]->value);
            $generic = false;
            $non_empty = false;
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
                        if ($atomic->value === '0') {
                            $non_empty = true;
                            continue;
                        }
                        continue;
                    }
                    if ($atomic instanceof Type\Atomic\T_Non_Empty_String) {
                        $non_empty = true;
                        continue;
                    }
                    $generic = true;
                    break;
                }
            }
            if ($union === null || $generic) {
                return Type::get_string();
            }
            if ($non_empty) {
                return Type::get_non_empty_string();
            }
            return Type::get_non_falsy_string();
        }
        $basename = basename($evaled_path);
        return Type::get_string($basename);
    }
}