<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider\Return_Type_Provider;

use Override;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Plugin\Event_Handler\Event\Method_Return_Type_Provider_Event;
use Psalm\Plugin\Event_Handler\Method_Return_Type_Provider_Interface;
use Psalm\Type;
use Psalm\Type\Union;
/**
 * @internal
 */
final class Dom_Node_Append_Child implements Method_Return_Type_Provider_Interface
{
    #[Override]
    public static function get_class_like_names(): array
    {
        return ['DomNode'];
    }
    #[Override]
    public static function get_method_return_type(Method_Return_Type_Provider_Event $event): ?Union
    {
        $source = $event->get_source();
        $call_args = $event->get_call_args();
        $method_name_lowercase = $event->get_method_name_lowercase();
        if ($method_name_lowercase !== 'appendchild') {
            return null;
        }
        if (!$source instanceof Statements_Analyzer || !$call_args) {
            return Type::get_mixed();
        }
        if (($first_arg_type = $source->node_data->get_type($call_args[0]->value)) && $first_arg_type->has_object_type()) {
            return $first_arg_type;
        }
        return null;
    }
}