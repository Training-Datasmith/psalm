<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Codebase\Variable_Use_Graph;
use Psalm\Internal\Data_Flow\Data_Flow_Node;
use Psalm\Issue\Invalid_Operand;
use Psalm\Issue\Possibly_Invalid_Operand;
use Psalm\Issue_Buffer;
use Psalm\Type;
use Psalm\Type\Atomic\T_Float;
use Psalm\Type\Atomic\T_Int;
use Psalm\Type\Atomic\T_Literal_Float;
use Psalm\Type\Atomic\T_Literal_Int;
use Psalm\Type\Atomic\T_Literal_String;
use Psalm\Type\Atomic\T_String;
use Psalm\Type\Union;
/**
 * @internal
 */
final class Bitwise_Not_Analyzer
{
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Bitwise_Not $stmt, Context $context): bool
    {
        if (Expression_Analyzer::analyze($statements_analyzer, $stmt->expr, $context) === false) {
            return false;
        }
        if (!$stmt_expr_type = $statements_analyzer->node_data->get_type($stmt->expr)) {
            $statements_analyzer->node_data->set_type($stmt, new Union([new T_Int(), new T_String()]));
        } elseif ($stmt_expr_type->is_mixed()) {
            $statements_analyzer->node_data->set_type($stmt, Type::get_mixed());
        } else {
            $acceptable_types = [];
            $unacceptable_type = null;
            $has_valid_operand = false;
            $stmt_expr_type = $stmt_expr_type->get_builder();
            foreach ($stmt_expr_type->get_atomic_types() as $type_string => $type_part) {
                if ($type_part instanceof T_Int || $type_part instanceof T_String) {
                    if ($type_part instanceof T_Literal_Int) {
                        $type_part = new T_Literal_Int(~$type_part->value);
                    } elseif ($type_part instanceof T_Literal_String) {
                        $type_part = Type::get_atomic_string_from_literal(~$type_part->value);
                    }
                    $acceptable_types[] = $type_part;
                    $has_valid_operand = true;
                } elseif ($type_part instanceof T_Float) {
                    $type_part = $type_part instanceof T_Literal_Float ? new T_Literal_Int(~$type_part->value) : new T_Int();
                    $stmt_expr_type->remove_type($type_string);
                    $stmt_expr_type->add_type($type_part);
                    $acceptable_types[] = $type_part;
                    $has_valid_operand = true;
                } elseif (!$unacceptable_type) {
                    $unacceptable_type = $type_part;
                }
            }
            if ($unacceptable_type || !$acceptable_types) {
                $message = 'Cannot negate a non-numeric non-string type ' . $unacceptable_type;
                if ($has_valid_operand) {
                    Issue_Buffer::maybe_add(new Possibly_Invalid_Operand($message, new Code_Location($statements_analyzer, $stmt)), $statements_analyzer->get_suppressed_issues());
                } else {
                    Issue_Buffer::maybe_add(new Invalid_Operand($message, new Code_Location($statements_analyzer, $stmt)), $statements_analyzer->get_suppressed_issues());
                }
                $statements_analyzer->node_data->set_type($stmt, Type::get_mixed());
            } else {
                $statements_analyzer->node_data->set_type($stmt, new Union($acceptable_types));
            }
        }
        self::add_data_flow($statements_analyzer, $stmt, $stmt->expr);
        return true;
    }
    private static function add_data_flow(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr $stmt, Php_Parser\Node\Expr $value): void
    {
        $result_type = $statements_analyzer->node_data->get_type($stmt);
        if ($statements_analyzer->data_flow_graph instanceof Variable_Use_Graph && $result_type) {
            $var_location = new Code_Location($statements_analyzer, $stmt);
            $stmt_value_type = $statements_analyzer->node_data->get_type($value);
            $new_parent_node = Data_Flow_Node::get_for_assignment('bitwisenot', $var_location);
            $statements_analyzer->data_flow_graph->add_node($new_parent_node);
            $result_type = $result_type->set_parent_nodes([$new_parent_node->id => $new_parent_node]);
            $statements_analyzer->node_data->set_type($stmt, $result_type);
            if ($stmt_value_type && $stmt_value_type->parent_nodes) {
                foreach ($stmt_value_type->parent_nodes as $parent_node) {
                    $statements_analyzer->data_flow_graph->add_path($parent_node, $new_parent_node, 'bitwisenot');
                }
            }
        }
    }
}