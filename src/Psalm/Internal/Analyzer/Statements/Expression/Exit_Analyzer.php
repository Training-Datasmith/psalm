<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression;

use Php_Parser\Node\Expr\Exit_;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Internal\Analyzer\Function_Like_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call\Argument_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Codebase\Taint_Flow_Graph;
use Psalm\Internal\Data_Flow\Taint_Sink;
use Psalm\Issue\Forbidden_Code;
use Psalm\Issue\Impure_Function_Call;
use Psalm\Issue_Buffer;
use Psalm\Storage\Function_Like_Parameter;
use Psalm\Type;
use Psalm\Type\Atomic\T_Int;
use Psalm\Type\Atomic\T_String;
use Psalm\Type\Taint_Kind;
use Psalm\Type\Union;
/**
 * @internal
 */
final class Exit_Analyzer
{
    public static function analyze(Statements_Analyzer $statements_analyzer, Exit_ $stmt, Context $context): bool
    {
        $expr_type = null;
        $config = $statements_analyzer->get_project_analyzer()->get_config();
        $forbidden = null;
        if (isset($config->forbidden_functions['exit']) && $stmt->get_attribute('kind') === Exit_::KIND_EXIT) {
            $forbidden = 'exit';
        } elseif (isset($config->forbidden_functions['die']) && $stmt->get_attribute('kind') === Exit_::KIND_DIE) {
            $forbidden = 'die';
        }
        if ($forbidden) {
            Issue_Buffer::maybe_add(new Forbidden_Code('You have forbidden the use of ' . $forbidden, new Code_Location($statements_analyzer, $stmt)), $statements_analyzer->get_suppressed_issues());
        }
        if ($stmt->expr) {
            $context->inside_call = true;
            if (Expression_Analyzer::analyze($statements_analyzer, $stmt->expr, $context) === false) {
                return false;
            }
            if ($statements_analyzer->data_flow_graph instanceof Taint_Flow_Graph) {
                $call_location = new Code_Location($statements_analyzer->get_source(), $stmt);
                $echo_param_sink = Taint_Sink::get_for_method_argument('exit', 'exit', 0, null, $call_location);
                $echo_param_sink->taints = [Taint_Kind::INPUT_HTML, Taint_Kind::INPUT_HAS_QUOTES, Taint_Kind::USER_SECRET, Taint_Kind::SYSTEM_SECRET];
                $statements_analyzer->data_flow_graph->add_sink($echo_param_sink);
            }
            if ($expr_type = $statements_analyzer->node_data->get_type($stmt->expr)) {
                $exit_param = new Function_Like_Parameter('var', false);
                if (Argument_Analyzer::verify_type($statements_analyzer, $expr_type, new Union([new T_Int(), new T_String()]), null, 'exit', null, 0, new Code_Location($statements_analyzer->get_source(), $stmt->expr), $stmt->expr, $context, $exit_param, false, null, true, true, new Code_Location($statements_analyzer, $stmt)) === false) {
                    return false;
                }
            }
            $context->inside_call = false;
        }
        if ($expr_type && !$expr_type->is_int() && !$context->collect_mutations && !$context->collect_initializations) {
            if ($context->mutation_free || $context->external_mutation_free) {
                $function_name = $stmt->get_attribute('kind') === Exit_::KIND_DIE ? 'die' : 'exit';
                Issue_Buffer::maybe_add(new Impure_Function_Call('Cannot call ' . $function_name . ' with a non-integer argument from a mutation-free context', new Code_Location($statements_analyzer, $stmt)), $statements_analyzer->get_suppressed_issues());
            } elseif ($statements_analyzer->get_source() instanceof Function_Like_Analyzer && $statements_analyzer->get_source()->track_mutations) {
                $statements_analyzer->get_source()->inferred_has_mutation = true;
                $statements_analyzer->get_source()->inferred_impure = true;
            }
        }
        $statements_analyzer->node_data->set_type($stmt, Type::get_never());
        $context->has_returned = true;
        return true;
    }
}