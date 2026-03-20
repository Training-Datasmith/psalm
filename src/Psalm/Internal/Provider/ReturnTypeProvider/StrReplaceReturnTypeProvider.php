<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider\Return_Type_Provider;

use Override;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Plugin\Event_Handler\Event\Function_Return_Type_Provider_Event;
use Psalm\Plugin\Event_Handler\Function_Return_Type_Provider_Interface;
use Psalm\Type;
use Psalm\Type\Union;
use function call_user_func;
use function count;
/**
 * @internal
 */
final class Str_Replace_Return_Type_Provider implements Function_Return_Type_Provider_Interface
{
    /**
     * @return array<lowercase-string>
     */
    #[Override]
    public static function get_function_ids(): array
    {
        return ['str_replace', 'str_ireplace'];
    }
    #[Override]
    public static function get_function_return_type(Function_Return_Type_Provider_Event $event): ?Union
    {
        $statements_source = $event->get_statements_source();
        $call_args = $event->get_call_args();
        $function_id = $event->get_function_id();
        if (!$statements_source instanceof Statements_Analyzer || count($call_args) < 3) {
            // use the defaults, it will already report an error for the invalid params
            return null;
        }
        if ($subject_type = $statements_source->node_data->get_type($call_args[2]->value)) {
            if (!$subject_type->is_single_string_literal()) {
                return null;
            }
            $first_arg = $statements_source->node_data->get_type($call_args[0]->value);
            $second_arg = $statements_source->node_data->get_type($call_args[1]->value);
            if ($first_arg && $second_arg && $first_arg->is_single_string_literal() && $second_arg->is_single_string_literal()) {
                /**
                 * @var string $replaced_string
                 */
                $replaced_string = call_user_func($function_id, $first_arg->get_single_string_literal()->value, $second_arg->get_single_string_literal()->value, $subject_type->get_single_string_literal()->value);
                return Type::get_string($replaced_string);
            }
        }
        return null;
    }
}