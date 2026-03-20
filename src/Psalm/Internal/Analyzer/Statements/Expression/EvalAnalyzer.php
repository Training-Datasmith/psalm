<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Codebase\Taint_Flow_Graph;
use Psalm\Internal\Data_Flow\Taint_Sink;
use Psalm\Internal\Data_Flow\Taint_Source;
use Psalm\Issue\Forbidden_Code;
use Psalm\Issue_Buffer;
use Psalm\Plugin\Event_Handler\Event\Add_Remove_Taints_Event;
use Psalm\Type\Taint_Kind;
use function array_diff;
use function in_array;
/**
 * @internal
 */
final class Eval_Analyzer
{
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Eval_ $stmt, Context $context): void
    {
        $was_inside_call = $context->inside_call;
        $context->inside_call = true;
        Expression_Analyzer::analyze($statements_analyzer, $stmt->expr, $context);
        $context->inside_call = $was_inside_call;
        $codebase = $statements_analyzer->get_codebase();
        $expr_type = $statements_analyzer->node_data->get_type($stmt->expr);
        if ($expr_type) {
            if ($statements_analyzer->data_flow_graph instanceof Taint_Flow_Graph && $expr_type->parent_nodes && !in_array('TaintedInput', $statements_analyzer->get_suppressed_issues())) {
                $arg_location = new Code_Location($statements_analyzer->get_source(), $stmt->expr);
                $eval_param_sink = Taint_Sink::get_for_method_argument('eval', 'eval', 0, $arg_location, $arg_location);
                $eval_param_sink->taints = [Taint_Kind::INPUT_EVAL];
                $statements_analyzer->data_flow_graph->add_sink($eval_param_sink);
                $codebase = $statements_analyzer->get_codebase();
                $event = new Add_Remove_Taints_Event($stmt, $context, $statements_analyzer, $codebase);
                $added_taints = $codebase->config->event_dispatcher->dispatch_add_taints($event);
                $removed_taints = $codebase->config->event_dispatcher->dispatch_remove_taints($event);
                $taints = array_diff($added_taints, $removed_taints);
                if ($taints !== []) {
                    $taint_source = Taint_Source::from_node($eval_param_sink);
                    $taint_source->taints = $taints;
                    $statements_analyzer->data_flow_graph->add_source($taint_source);
                }
                foreach ($expr_type->parent_nodes as $parent_node) {
                    $statements_analyzer->data_flow_graph->add_path($parent_node, $eval_param_sink, 'arg', $added_taints, $removed_taints);
                }
            }
        }
        if (isset($codebase->config->forbidden_functions['eval'])) {
            Issue_Buffer::maybe_add(new Forbidden_Code('You have forbidden the use of eval', new Code_Location($statements_analyzer, $stmt)), $statements_analyzer->get_suppressed_issues());
        }
        $context->check_classes = false;
        $context->check_variables = false;
    }
}