<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression;

use Php_Parser;
use Psalm\Context;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Node\Expr\Binary_Op\Virtual_Identical;
use Psalm\Node\Expr\Virtual_Const_Fetch;
use Psalm\Node\Expr\Virtual_Method_Call;
use Psalm\Node\Expr\Virtual_Property_Fetch;
use Psalm\Node\Expr\Virtual_Ternary;
use Psalm\Node\Expr\Virtual_Variable;
use Psalm\Node\Virtual_Name;
use Psalm\Type;
/**
 * @internal
 */
final class Nullsafe_Analyzer
{
    /**
     * @param PhpParser\Node\Expr\NullsafePropertyFetch|PhpParser\Node\Expr\NullsafeMethodCall $stmt
     */
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr $stmt, Context $context): bool
    {
        if (!$stmt->var instanceof Php_Parser\Node\Expr\Variable) {
            $was_inside_general_use = $context->inside_general_use;
            $context->inside_general_use = true;
            Expression_Analyzer::analyze($statements_analyzer, $stmt->var, $context);
            $context->inside_general_use = $was_inside_general_use;
            $tmp_name = '__tmp_nullsafe__' . (int) $stmt->var->get_attribute('startFilePos');
            $condition_type = $statements_analyzer->node_data->get_type($stmt->var);
            if ($condition_type) {
                $context->vars_in_scope['$' . $tmp_name] = $condition_type;
                $tmp_var = new Virtual_Variable($tmp_name, $stmt->var->get_attributes());
            } else {
                $tmp_var = $stmt->var;
            }
        } else {
            $tmp_var = $stmt->var;
        }
        $old_node_data = $statements_analyzer->node_data;
        $statements_analyzer->node_data = clone $statements_analyzer->node_data;
        $null_value1 = new Virtual_Const_Fetch(new Virtual_Name('null'), $stmt->var->get_attributes());
        $null_comparison = new Virtual_Identical($tmp_var, $null_value1, $stmt->var->get_attributes());
        $null_value2 = new Virtual_Const_Fetch(new Virtual_Name('null'), $stmt->var->get_attributes());
        if ($stmt instanceof Php_Parser\Node\Expr\Nullsafe_Property_Fetch) {
            $ternary = new Virtual_Ternary($null_comparison, $null_value2, new Virtual_Property_Fetch($tmp_var, $stmt->name, $stmt->get_attributes()), $stmt->get_attributes());
        } else {
            $ternary = new Virtual_Ternary($null_comparison, $null_value2, new Virtual_Method_Call($tmp_var, $stmt->name, $stmt->args, $stmt->get_attributes()), $stmt->get_attributes());
        }
        Expression_Analyzer::analyze($statements_analyzer, $ternary, $context);
        $ternary_type = $statements_analyzer->node_data->get_type($ternary);
        $statements_analyzer->node_data = $old_node_data;
        $statements_analyzer->node_data->set_type($stmt, $ternary_type ?? Type::get_mixed());
        return true;
    }
}