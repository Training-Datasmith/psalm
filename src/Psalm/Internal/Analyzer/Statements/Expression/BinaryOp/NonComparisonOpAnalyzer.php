<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression\Binary_Op;

use Php_Parser;
use Psalm\Context;
use Psalm\Internal\Analyzer\Statements\Expression\Binary_Op_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Type;
use Psalm\Type\Atomic\T_Float;
use Psalm\Type\Atomic\T_Int;
use Psalm\Type\Union;
/**
 * @internal
 */
final class Non_Comparison_Op_Analyzer
{
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Binary_Op $stmt, Context $context): void
    {
        $stmt_left_type = $statements_analyzer->node_data->get_type($stmt->left);
        $stmt_right_type = $statements_analyzer->node_data->get_type($stmt->right);
        if (!$stmt_left_type || !$stmt_right_type) {
            return;
        }
        if (($stmt instanceof Php_Parser\Node\Expr\Binary_Op\Bitwise_Or || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Bitwise_Xor || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Bitwise_And) && $stmt_left_type->has_string() && $stmt_right_type->has_string()) {
            $stmt_type = Type::get_string();
            $statements_analyzer->node_data->set_type($stmt, $stmt_type);
            Binary_Op_Analyzer::add_data_flow($statements_analyzer, $stmt, $stmt->left, $stmt->right, 'nondivop');
            return;
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Binary_Op\Plus || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Minus || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Mod || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Mul || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Pow || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Bitwise_Or || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Bitwise_Xor || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Bitwise_And || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Shift_Left || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Shift_Right) {
            Arithmetic_Op_Analyzer::analyze($statements_analyzer, $statements_analyzer->node_data, $stmt->left, $stmt->right, $stmt, $result_type, $context);
            if (!$result_type) {
                $result_type = new Union([new T_Int(), new T_Float()]);
            }
            $statements_analyzer->node_data->set_type($stmt, $result_type);
            Binary_Op_Analyzer::add_data_flow($statements_analyzer, $stmt, $stmt->left, $stmt->right, 'nondivop');
            return;
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Binary_Op\Logical_Xor) {
            if ($stmt_left_type->has_bool() || $stmt_right_type->has_bool()) {
                $statements_analyzer->node_data->set_type($stmt, Type::get_bool());
            }
            Binary_Op_Analyzer::add_data_flow($statements_analyzer, $stmt, $stmt->left, $stmt->right, 'xor');
            return;
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Binary_Op\Div) {
            Arithmetic_Op_Analyzer::analyze($statements_analyzer, $statements_analyzer->node_data, $stmt->left, $stmt->right, $stmt, $result_type, $context);
            if (!$result_type) {
                $result_type = new Union([new T_Int(), new T_Float()]);
            }
            $statements_analyzer->node_data->set_type($stmt, $result_type);
            Binary_Op_Analyzer::add_data_flow($statements_analyzer, $stmt, $stmt->left, $stmt->right, 'div');
            return;
        }
    }
}