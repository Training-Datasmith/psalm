<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Block;

use Php_Parser;
use Psalm\Context;
use Psalm\Internal\Analyzer\Statements_Analyzer;
/**
 * @internal
 */
final class While_Analyzer
{
    /**
     * @return  false|null
     */
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Stmt\While_ $stmt, Context $context): ?bool
    {
        $while_true = $stmt->cond instanceof Php_Parser\Node\Expr\Const_Fetch && $stmt->cond->name->get_parts() === ['true'] || ($t = $statements_analyzer->node_data->get_type($stmt->cond)) && $t->is_always_truthy();
        return Loop_Analyzer::analyze_for_or_while($statements_analyzer, $stmt, $context, $while_true, [], [], self::get_and_expressions($stmt->cond), []);
    }
    /**
     * @return list<PhpParser\Node\Expr>
     */
    public static function get_and_expressions(Php_Parser\Node\Expr $expr): array
    {
        if ($expr instanceof Php_Parser\Node\Expr\Binary_Op\Boolean_And) {
            return [...self::get_and_expressions($expr->left), ...self::get_and_expressions($expr->right)];
        }
        return [$expr];
    }
}