<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression;

use Php_Parser;
use Php_Parser\Node\Expr\Post_Dec;
use Php_Parser\Node\Expr\Post_Inc;
use Php_Parser\Node\Expr\Pre_Dec;
use Php_Parser\Node\Expr\Pre_Inc;
use Psalm\Context;
use Psalm\Internal\Analyzer\Statements\Expression\Binary_Op\Arithmetic_Op_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Node\Expr\Binary_Op\Virtual_Minus;
use Psalm\Node\Expr\Binary_Op\Virtual_Plus;
use Psalm\Node\Expr\Virtual_Assign;
use Psalm\Node\Scalar\Virtual_Int;
use Psalm\Type;
/**
 * @internal
 */
final class Inc_Dec_Expression_Analyzer
{
    /**
     * @param PostInc|PostDec|PreInc|PreDec $stmt
     */
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr $stmt, Context $context): bool
    {
        $was_inside_assignment = $context->inside_assignment;
        $context->inside_assignment = true;
        if (Expression_Analyzer::analyze($statements_analyzer, $stmt->var, $context) === false) {
            $context->inside_assignment = $was_inside_assignment;
            return false;
        }
        $context->inside_assignment = $was_inside_assignment;
        $stmt_var_type = $statements_analyzer->node_data->get_type($stmt->var);
        if ($stmt instanceof Post_Inc || $stmt instanceof Post_Dec) {
            $statements_analyzer->node_data->set_type($stmt, $stmt_var_type ?? Type::get_mixed());
        }
        if (($stmt_var_type = $statements_analyzer->node_data->get_type($stmt->var)) && $stmt_var_type->has_string() && ($stmt instanceof Post_Inc || $stmt instanceof Pre_Inc)) {
            $return_type = null;
            $fake_right_expr = new Virtual_Int(1, $stmt->get_attributes());
            $statements_analyzer->node_data->set_type($fake_right_expr, Type::get_int());
            Arithmetic_Op_Analyzer::analyze($statements_analyzer, $statements_analyzer->node_data, $stmt->var, $fake_right_expr, $stmt, $return_type, $context);
            $result_type = $return_type ?? Type::get_mixed();
            $statements_analyzer->node_data->set_type($stmt, $result_type);
            Binary_Op_Analyzer::add_data_flow($statements_analyzer, $stmt, $stmt->var, $fake_right_expr, 'inc');
            $var_id = Expression_Identifier::get_extended_var_id($stmt->var, null);
            $codebase = $statements_analyzer->get_codebase();
            if ($var_id && isset($context->vars_in_scope[$var_id])) {
                $context->vars_in_scope[$var_id] = $result_type;
                if ($codebase->find_unused_variables && $stmt->var instanceof Php_Parser\Node\Expr\Variable) {
                    $context->assigned_var_ids[$var_id] = (int) $stmt->var->get_attribute('startFilePos');
                    $context->possibly_assigned_var_ids[$var_id] = true;
                }
                // removes dependent vars from $context
                $context->remove_descendents($var_id, $context->vars_in_scope[$var_id], $return_type, $statements_analyzer);
            }
        } else {
            $fake_right_expr = new Virtual_Int(1, $stmt->get_attributes());
            $operation = $stmt instanceof Post_Inc || $stmt instanceof Pre_Inc ? new Virtual_Plus($stmt->var, $fake_right_expr, $stmt->var->get_attributes()) : new Virtual_Minus($stmt->var, $fake_right_expr, $stmt->var->get_attributes());
            $fake_assignment = new Virtual_Assign($stmt->var, $operation, $stmt->get_attributes());
            $old_node_data = $statements_analyzer->node_data;
            $statements_analyzer->node_data = clone $statements_analyzer->node_data;
            if (Expression_Analyzer::analyze($statements_analyzer, $fake_assignment, $context) === false) {
                return false;
            }
            if ($stmt instanceof Pre_Inc || $stmt instanceof Pre_Dec) {
                $old_node_data->set_type($stmt, $statements_analyzer->node_data->get_type($fake_assignment) ?? Type::get_mixed());
            }
            $statements_analyzer->node_data = $old_node_data;
        }
        return true;
    }
}