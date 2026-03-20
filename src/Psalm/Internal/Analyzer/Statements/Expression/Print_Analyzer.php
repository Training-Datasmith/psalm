<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression;

use Php_Parser;
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
use Psalm\Type\Taint_Kind;
/**
 * @internal
 */
final class Print_Analyzer
{
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Print_ $stmt, Context $context): bool
    {
        $codebase = $statements_analyzer->get_codebase();
        if (Expression_Analyzer::analyze($statements_analyzer, $stmt->expr, $context) === false) {
            return false;
        }
        if ($statements_analyzer->data_flow_graph instanceof Taint_Flow_Graph) {
            $call_location = new Code_Location($statements_analyzer->get_source(), $stmt);
            $print_param_sink = Taint_Sink::get_for_method_argument('print', 'print', 0, null, $call_location);
            $print_param_sink->taints = [Taint_Kind::INPUT_HTML, Taint_Kind::INPUT_HAS_QUOTES, Taint_Kind::USER_SECRET, Taint_Kind::SYSTEM_SECRET];
            $statements_analyzer->data_flow_graph->add_sink($print_param_sink);
        }
        if ($stmt_expr_type = $statements_analyzer->node_data->get_type($stmt->expr)) {
            if (Argument_Analyzer::verify_type($statements_analyzer, $stmt_expr_type, Type::get_string(), null, 'print', null, 0, new Code_Location($statements_analyzer->get_source(), $stmt->expr), $stmt->expr, $context, new Function_Like_Parameter('var', false), false, null, true, true, new Code_Location($statements_analyzer->get_source(), $stmt)) === false) {
                return false;
            }
        }
        if (isset($codebase->config->forbidden_functions['print'])) {
            Issue_Buffer::maybe_add(new Forbidden_Code('You have forbidden the use of print', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
        }
        if (!$context->collect_initializations && !$context->collect_mutations) {
            if ($context->mutation_free || $context->external_mutation_free) {
                Issue_Buffer::maybe_add(new Impure_Function_Call('Cannot call print from a mutation-free context', new Code_Location($statements_analyzer, $stmt)), $statements_analyzer->get_suppressed_issues());
            } elseif ($statements_analyzer->get_source() instanceof Function_Like_Analyzer && $statements_analyzer->get_source()->track_mutations) {
                $statements_analyzer->get_source()->inferred_has_mutation = true;
                $statements_analyzer->get_source()->inferred_impure = true;
            }
        }
        $statements_analyzer->node_data->set_type($stmt, Type::get_int(false, 1));
        return true;
    }
}