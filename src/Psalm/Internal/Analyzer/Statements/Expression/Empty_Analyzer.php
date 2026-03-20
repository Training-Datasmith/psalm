<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Issue\Forbidden_Code;
use Psalm\Issue\Invalid_Argument;
use Psalm\Issue_Buffer;
use Psalm\Type;
use Psalm\Type\Atomic\T_Bool;
use Psalm\Type\Atomic\T_False;
use Psalm\Type\Atomic\T_True;
use Psalm\Type\Union;
/**
 * @internal
 */
final class Empty_Analyzer
{
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Empty_ $stmt, Context $context): void
    {
        Isset_Analyzer::analyze_isset_var($statements_analyzer, $stmt->expr, $context);
        $codebase = $statements_analyzer->get_codebase();
        if (isset($codebase->config->forbidden_functions['empty'])) {
            Issue_Buffer::maybe_add(new Forbidden_Code('You have forbidden the use of empty', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
        }
        $expr_type = $statements_analyzer->node_data->get_type($stmt->expr);
        if ($expr_type) {
            if ($expr_type->has_bool() && $expr_type->is_single() && !$expr_type->from_docblock) {
                Issue_Buffer::maybe_add(new Invalid_Argument('Calling empty on a boolean value is almost certainly unintended', new Code_Location($statements_analyzer->get_source(), $stmt->expr), 'empty'), $statements_analyzer->get_suppressed_issues());
            }
            if ($expr_type->is_always_truthy() && $expr_type->possibly_undefined === false) {
                $stmt_type = new T_False($expr_type->from_docblock);
            } elseif ($expr_type->is_always_falsy()) {
                $stmt_type = new T_True($expr_type->from_docblock);
            } else {
                Expression_Analyzer::check_risky_truthy_falsy_comparison($expr_type, $statements_analyzer, $stmt);
                $stmt_type = new T_Bool();
            }
            $stmt_type = new Union([$stmt_type], ['parent_nodes' => $expr_type->parent_nodes]);
        } else {
            $stmt_type = Type::get_bool();
        }
        $statements_analyzer->node_data->set_type($stmt, $stmt_type);
    }
}