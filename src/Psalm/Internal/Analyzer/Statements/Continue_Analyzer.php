<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Internal\Analyzer\Scope_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Issue\Continue_Outside_Loop;
use Psalm\Issue_Buffer;
use Psalm\Type;
use function end;
/**
 * @internal
 */
final class Continue_Analyzer
{
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Stmt\Continue_ $stmt, Context $context): void
    {
        $count = $stmt->num instanceof Php_Parser\Node\Scalar\Int_ ? $stmt->num->value : 1;
        $loop_scope = $context->loop_scope;
        if ($count === 2 && isset($loop_scope->loop_parent_context->loop_scope)) {
            $loop_scope = $loop_scope->loop_parent_context->loop_scope;
        }
        if ($count === 3 && isset($loop_scope->loop_parent_context->loop_scope)) {
            $loop_scope = $loop_scope->loop_parent_context->loop_scope;
        }
        if ($loop_scope === null) {
            if (!$context->break_types) {
                if (Issue_Buffer::accepts(new Continue_Outside_Loop('Continue call outside loop context', new Code_Location($statements_analyzer, $stmt)), $statements_analyzer->get_source()->get_suppressed_issues())) {
                    return;
                }
            }
        } else {
            if ($context->break_types && end($context->break_types) === 'switch' && $count < 2) {
                $loop_scope->final_actions[] = Scope_Analyzer::ACTION_LEAVE_SWITCH;
            } else {
                $loop_scope->final_actions[] = Scope_Analyzer::ACTION_CONTINUE;
            }
            $redefined_vars = $context->get_redefined_vars($loop_scope->loop_parent_context->vars_in_scope);
            foreach ($loop_scope->redefined_loop_vars as $redefined_var => $type) {
                if (!isset($redefined_vars[$redefined_var])) {
                    unset($loop_scope->redefined_loop_vars[$redefined_var]);
                } else {
                    $loop_scope->redefined_loop_vars[$redefined_var] = Type::combine_union_types($redefined_vars[$redefined_var], $type);
                }
            }
            foreach ($redefined_vars as $var => $type) {
                $loop_scope->possibly_redefined_loop_vars[$var] = Type::combine_union_types($type, $loop_scope->possibly_redefined_loop_vars[$var] ?? null);
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
            }
        }
        $context->has_returned = true;
    }
}