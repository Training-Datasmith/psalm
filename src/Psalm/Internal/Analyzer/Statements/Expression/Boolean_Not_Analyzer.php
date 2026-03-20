<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression;

use Php_Parser;
use Psalm\Context;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Type;
use Psalm\Type\Atomic\T_Bool;
use Psalm\Type\Atomic\T_False;
use Psalm\Type\Atomic\T_True;
use Psalm\Type\Union;
/**
 * @internal
 */
final class Boolean_Not_Analyzer
{
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Boolean_Not $stmt, Context $context): bool
    {
        $inside_negation = $context->inside_negation;
        $context->inside_negation = !$inside_negation;
        $result = Expression_Analyzer::analyze($statements_analyzer, $stmt->expr, $context);
        $context->inside_negation = $inside_negation;
        $expr_type = $statements_analyzer->node_data->get_type($stmt->expr);
        if ($expr_type) {
            if ($expr_type->is_always_truthy()) {
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
        return $result;
    }
}