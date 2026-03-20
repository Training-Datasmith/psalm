<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression\Binary_Op;

use Php_Parser;
use Psalm\Context;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Node\Expr\Virtual_Isset;
use Psalm\Node\Expr\Virtual_Ternary;
use Psalm\Node\Expr\Virtual_Variable;
use Psalm\Type;
use Psalm\Type\Atomic\T_Mixed;
use Psalm\Type\Union;
use function substr;
/**
 * @internal
 */
final class Coalesce_Analyzer
{
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Binary_Op\Coalesce $stmt, Context $context): bool
    {
        $left_expr = $stmt->left;
        $root_expr = $left_expr;
        while ($root_expr instanceof Php_Parser\Node\Expr\Array_Dim_Fetch || $root_expr instanceof Php_Parser\Node\Expr\Property_Fetch) {
            $root_expr = $root_expr->var;
        }
        if ($root_expr instanceof Php_Parser\Node\Expr\Func_Call || $root_expr instanceof Php_Parser\Node\Expr\Method_Call || $root_expr instanceof Php_Parser\Node\Expr\Static_Call || $root_expr instanceof Php_Parser\Node\Expr\Cast || $root_expr instanceof Php_Parser\Node\Expr\Match_ || $root_expr instanceof Php_Parser\Node\Expr\Nullsafe_Property_Fetch || $root_expr instanceof Php_Parser\Node\Expr\Nullsafe_Method_Call || $root_expr instanceof Php_Parser\Node\Expr\Ternary) {
            $left_var_id = '$<tmp coalesce var>' . (int) $left_expr->get_attribute('startFilePos');
            $cloned = clone $context;
            $cloned->inside_isset = true;
            Expression_Analyzer::analyze($statements_analyzer, $left_expr, $cloned);
            if ($root_expr !== $left_expr) {
                $condition_type = $statements_analyzer->node_data->get_type($left_expr);
                if ($condition_type) {
                    $condition_type = $condition_type->set_possibly_undefined(true);
                } else {
                    $condition_type = new Union([new T_Mixed()], ['possibly_undefined' => true]);
                }
            } else {
                $condition_type = $statements_analyzer->node_data->get_type($left_expr) ?? Type::get_mixed();
            }
            $context->vars_in_scope[$left_var_id] = $condition_type;
            $left_expr = new Virtual_Variable(substr($left_var_id, 1), $left_expr->get_attributes());
        }
        $ternary = new Virtual_Ternary(new Virtual_Isset([$left_expr], $stmt->left->get_attributes()), $left_expr, $stmt->right, $stmt->get_attributes());
        $old_node_data = $statements_analyzer->node_data;
        $statements_analyzer->node_data = clone $statements_analyzer->node_data;
        Expression_Analyzer::analyze($statements_analyzer, $ternary, $context);
        $ternary_type = $statements_analyzer->node_data->get_type($ternary) ?? Type::get_mixed();
        $statements_analyzer->node_data = $old_node_data;
        $statements_analyzer->node_data->set_type($stmt, $ternary_type);
        return true;
    }
}