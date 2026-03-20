<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Block;

use Php_Parser;
use Psalm\Context;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use function array_merge;
use function is_string;
/**
 * @internal
 */
final class For_Analyzer
{
    /**
     * @return  false|null
     */
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Stmt\For_ $stmt, Context $context): ?bool
    {
        $pre_assigned_var_ids = $context->assigned_var_ids;
        $context->assigned_var_ids = [];
        $init_var_types = [];
        foreach ($stmt->init as $init) {
            if (Expression_Analyzer::analyze($statements_analyzer, $init, $context) === false) {
                return false;
            }
            if ($init instanceof Php_Parser\Node\Expr\Assign && $init->var instanceof Php_Parser\Node\Expr\Variable && is_string($init->var->name) && $init_var_type = $statements_analyzer->node_data->get_type($init->expr)) {
                $init_var_types[$init->var->name] = $init_var_type;
            }
        }
        $assigned_var_ids = $context->assigned_var_ids;
        $context->assigned_var_ids = array_merge($pre_assigned_var_ids, $assigned_var_ids);
        $while_true = !$stmt->cond && !$stmt->init && !$stmt->loop;
        return Loop_Analyzer::analyze_for_or_while($statements_analyzer, $stmt, $context, $while_true, $init_var_types, $assigned_var_ids, $stmt->cond, $stmt->loop);
    }
}