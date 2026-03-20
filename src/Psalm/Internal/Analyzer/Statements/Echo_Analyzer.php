<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Internal\Analyzer\Function_Like_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call\Argument_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Cast_Analyzer;
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
final class Echo_Analyzer
{
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Stmt\Echo_ $stmt, Context $context): bool
    {
        $echo_param = new Function_Like_Parameter('var', false);
        $codebase = $statements_analyzer->get_codebase();
        foreach ($stmt->exprs as $i => $expr) {
            $context->inside_call = true;
            Expression_Analyzer::analyze($statements_analyzer, $expr, $context);
            $context->inside_call = false;
            $expr_type = $statements_analyzer->node_data->get_type($expr);
            if ($statements_analyzer->data_flow_graph instanceof Taint_Flow_Graph) {
                if ($expr_type && $expr_type->has_object_type()) {
                    $expr_type = Cast_Analyzer::cast_string_attempt($statements_analyzer, $context, $expr_type, $expr, false);
                }
                $call_location = new Code_Location($statements_analyzer->get_source(), $stmt);
                $echo_param_sink = Taint_Sink::get_for_method_argument('echo', 'echo', (int) $i, null, $call_location);
                $echo_param_sink->taints = [Taint_Kind::INPUT_HTML, Taint_Kind::INPUT_HAS_QUOTES, Taint_Kind::USER_SECRET, Taint_Kind::SYSTEM_SECRET];
                $statements_analyzer->data_flow_graph->add_sink($echo_param_sink);
            }
            if (Argument_Analyzer::verify_type($statements_analyzer, $expr_type ?? Type::get_mixed(), Type::get_string(), null, 'echo', null, (int) $i, new Code_Location($statements_analyzer->get_source(), $expr), $expr, $context, $echo_param, false, null, true, true, new Code_Location($statements_analyzer, $stmt)) === false) {
                return false;
            }
        }
        if (isset($codebase->config->forbidden_functions['echo'])) {
            Issue_Buffer::maybe_add(new Forbidden_Code('Use of echo', new Code_Location($statements_analyzer, $stmt)), $statements_analyzer->get_source()->get_suppressed_issues());
        }
        if (!$context->collect_initializations && !$context->collect_mutations) {
            if ($context->mutation_free || $context->external_mutation_free) {
                Issue_Buffer::maybe_add(new Impure_Function_Call('Cannot call echo from a mutation-free context', new Code_Location($statements_analyzer, $stmt)), $statements_analyzer->get_suppressed_issues());
            } elseif ($statements_analyzer->get_source() instanceof Function_Like_Analyzer && $statements_analyzer->get_source()->track_mutations) {
                $statements_analyzer->get_source()->inferred_has_mutation = true;
                $statements_analyzer->get_source()->inferred_impure = true;
            }
        }
        return true;
    }
}