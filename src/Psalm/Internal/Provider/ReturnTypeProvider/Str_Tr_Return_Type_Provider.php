<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider\Return_Type_Provider;

use Override;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Data_Flow\Data_Flow_Node;
use Psalm\Plugin\Event_Handler\Event\Function_Return_Type_Provider_Event;
use Psalm\Plugin\Event_Handler\Function_Return_Type_Provider_Interface;
use Psalm\Type;
use Psalm\Type\Union;
use UnexpectedValueException;
use function in_array;
/**
 * @internal
 */
final class Str_Tr_Return_Type_Provider implements Function_Return_Type_Provider_Interface
{
    /**
     * @return array<lowercase-string>
     */
    #[Override]
    public static function get_function_ids(): array
    {
        return ['strtr'];
    }
    #[Override]
    public static function get_function_return_type(Function_Return_Type_Provider_Event $event): Union
    {
        $statements_source = $event->get_statements_source();
        $call_args = $event->get_call_args();
        $function_id = $event->get_function_id();
        $code_location = $event->get_code_location();
        if (!$statements_source instanceof Statements_Analyzer) {
            throw new UnexpectedValueException();
        }
        $type = Type::get_string();
        if ($statements_source->data_flow_graph && !in_array('TaintedInput', $statements_source->get_suppressed_issues())) {
            $function_return_sink = Data_Flow_Node::get_for_method_return($function_id, $function_id, null, $code_location);
            $statements_source->data_flow_graph->add_node($function_return_sink);
            foreach ($call_args as $i => $_) {
                $function_param_sink = Data_Flow_Node::get_for_method_argument($function_id, $function_id, $i, null, $code_location);
                $statements_source->data_flow_graph->add_node($function_param_sink);
                $statements_source->data_flow_graph->add_path($function_param_sink, $function_return_sink, 'arg');
            }
            return $type->set_parent_nodes([$function_return_sink->id => $function_return_sink]);
        }
        return $type;
    }
}