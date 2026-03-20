<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider\Return_Type_Provider;

use DateTime;
use Override;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Plugin\Event_Handler\Event\Method_Return_Type_Provider_Event;
use Psalm\Plugin\Event_Handler\Method_Return_Type_Provider_Interface;
use Psalm\Type;
use Psalm\Type\Atomic\T_Literal_String;
use Psalm\Type\Union;
/**
 * @internal
 */
final class Date_Time_Modify_Return_Type_Provider implements Method_Return_Type_Provider_Interface
{
    #[Override]
    public static function get_class_like_names(): array
    {
        return ['DateTime', 'DateTimeImmutable'];
    }
    #[Override]
    public static function get_method_return_type(Method_Return_Type_Provider_Event $event): ?Union
    {
        $statements_source = $event->get_source();
        $call_args = $event->get_call_args();
        $method_name_lowercase = $event->get_method_name_lowercase();
        if (!$statements_source instanceof Statements_Analyzer || $method_name_lowercase !== 'modify' || !isset($call_args[0])) {
            return null;
        }
        $first_arg = $call_args[0]->value;
        $first_arg_type = $statements_source->node_data->get_type($first_arg);
        if (!$first_arg_type) {
            return null;
        }
        $has_date_time = false;
        $has_false = false;
        foreach ($first_arg_type->get_atomic_types() as $type_part) {
            if (!$type_part instanceof T_Literal_String) {
                return null;
            }
            if (@(new DateTime())->modify($type_part->value) === false) {
                $has_false = true;
            } else {
                $has_date_time = true;
            }
        }
        if ($has_false && !$has_date_time) {
            return Type::get_false();
        }
        if (!$has_false) {
            return Type::parse_string($event->get_called_fq_classlike_name() ?? $event->get_fq_classlike_name());
        }
        return null;
    }
}