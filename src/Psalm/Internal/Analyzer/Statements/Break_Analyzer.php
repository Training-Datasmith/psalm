<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements;

use Php_Parser;
use Psalm\Context;
use Psalm\Internal\Analyzer\Scope_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Type;
use function end;
/**
 * @internal
 */
final class Break_Analyzer
{
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Stmt\Break_ $stmt, Context $context): void
    {
        $loop_scope = $context->loop_scope;
        $leaving_switch = true;
        if ($loop_scope) {
            if ($context->break_types && end($context->break_types) === 'switch' && (!$stmt->num instanceof Php_Parser\Node\Scalar\Int_ || $stmt->num->value < 2)) {
                $loop_scope->final_actions[] = Scope_Analyzer::ACTION_LEAVE_SWITCH;
            } else {
                $leaving_switch = false;
                $loop_scope->final_actions[] = Scope_Analyzer::ACTION_BREAK;
            }
            $redefined_vars = $context->get_redefined_vars($loop_scope->loop_parent_context->vars_in_scope);
            foreach ($redefined_vars as $var => $type) {
                $loop_scope->possibly_redefined_loop_parent_vars[$var] = Type::combine_union_types($type, $loop_scope->possibly_redefined_loop_parent_vars[$var] ?? null);
            }
            if ($loop_scope->iteration_count === 0) {
                foreach ($context->vars_in_scope as $var_id => $type) {
                    if (!isset($loop_scope->loop_parent_context->vars_in_scope[$var_id])) {
                        $loop_scope->possibly_defined_loop_parent_vars[$var_id] = Type::combine_union_types($type, $loop_scope->possibly_defined_loop_parent_vars[$var_id] ?? null);
                    }
                }
            }
            if ($context->finally_scope) {
                foreach ($context->vars_in_scope as $var_id => &$type) {
                    if (isset($context->finally_scope->vars_in_scope[$var_id])) {
                        $context->finally_scope->vars_in_scope[$var_id] = Type::combine_union_types($context->finally_scope->vars_in_scope[$var_id], $type, $statements_analyzer->get_codebase());
                    } else {
                        $type = $type->set_possibly_undefined(true, true);
                        $context->finally_scope->vars_in_scope[$var_id] = $type;
                    }
                }
                unset($type);
            }
        }
        $case_scope = $context->case_scope;
        if ($case_scope && $leaving_switch) {
            foreach ($context->vars_in_scope as $var_id => $type) {
                if ($case_scope->break_vars === null) {
                    $case_scope->break_vars = [];
                }
                $case_scope->break_vars[$var_id] = Type::combine_union_types($type, $case_scope->break_vars[$var_id] ?? null);
            }
        }
        $context->has_returned = true;
    }
}