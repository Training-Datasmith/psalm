<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression;

use Php_Parser;
use Php_Parser\Node\Expr\Unary_Minus;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Codebase\Variable_Use_Graph;
use Psalm\Internal\Data_Flow\Data_Flow_Node;
use Psalm\Type;
use Psalm\Type\Atomic\T_Float;
use Psalm\Type\Atomic\T_Int;
use Psalm\Type\Atomic\T_Int_Range;
use Psalm\Type\Atomic\T_Literal_Float;
use Psalm\Type\Atomic\T_Literal_Int;
use Psalm\Type\Atomic\T_String;
use Psalm\Type\Union;
use RuntimeException;
use function is_int;
/**
 * @internal
 */
final class Unary_Plus_Minus_Analyzer
{
    /**
     * @param PhpParser\Node\Expr\UnaryMinus|PhpParser\Node\Expr\UnaryPlus $stmt
     */
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr $stmt, Context $context): bool
    {
        if (Expression_Analyzer::analyze($statements_analyzer, $stmt->expr, $context) === false) {
            return false;
        }
        if (!$stmt_expr_type = $statements_analyzer->node_data->get_type($stmt->expr)) {
            $statements_analyzer->node_data->set_type($stmt, new Union([new T_Int(), new T_Float()]));
        } elseif ($stmt_expr_type->is_mixed()) {
            $statements_analyzer->node_data->set_type($stmt, Type::get_mixed());
        } else {
            $acceptable_types = [];
            foreach ($stmt_expr_type->get_atomic_types() as $type_part) {
                if ($type_part instanceof T_Int || $type_part instanceof T_Float) {
                    if (!$stmt instanceof Php_Parser\Node\Expr\Unary_Minus) {
                        $acceptable_types[] = $type_part;
                        continue;
                    }
                    if ($type_part instanceof T_Literal_Int) {
                        /** @var int|float $value */
                        $value = -$type_part->value;
                        $type_part = is_int($value) ? new T_Literal_Int($value) : new T_Literal_Float($value);
                    } elseif ($type_part instanceof T_Literal_Float) {
                        $type_part = new T_Literal_Float(-$type_part->value);
                    } elseif ($type_part instanceof T_Int_Range) {
                        //we'll have to inverse min and max bound and negate any literal
                        $old_min_bound = $type_part->min_bound;
                        $old_max_bound = $type_part->max_bound;
                        if ($old_min_bound === null) {
                            //min bound is null, max bound will be null
                            $new_max_bound = null;
                        } elseif ($old_min_bound === 0) {
                            $new_max_bound = 0;
                        } else {
                            $new_max_bound = -$old_min_bound;
                        }
                        if ($old_max_bound === null) {
                            //max bound is null, min bound will be null
                            $new_min_bound = null;
                        } elseif ($old_max_bound === 0) {
                            $new_min_bound = 0;
                        } else {
                            $new_min_bound = -$old_max_bound;
                        }
                        $type_part = new T_Int_Range($new_min_bound, $new_max_bound);
                    }
                    $acceptable_types[] = $type_part;
                } elseif ($type_part instanceof T_String) {
                    $acceptable_types[] = new T_Int();
                    $acceptable_types[] = new T_Float();
                } else {
                    $acceptable_types[] = new T_Int();
                }
            }
            if (!$acceptable_types) {
                throw new RuntimeException("Impossible!");
            }
            $statements_analyzer->node_data->set_type($stmt, new Union($acceptable_types));
        }
        self::add_data_flow($statements_analyzer, $stmt, $stmt->expr, $stmt instanceof Unary_Minus ? 'unary-minus' : 'unary-plus');
        return true;
    }
    private static function add_data_flow(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr $stmt, Php_Parser\Node\Expr $value, string $type): void
    {
        $result_type = $statements_analyzer->node_data->get_type($stmt);
        if ($statements_analyzer->data_flow_graph instanceof Variable_Use_Graph && $result_type) {
            $var_location = new Code_Location($statements_analyzer, $stmt);
            $stmt_value_type = $statements_analyzer->node_data->get_type($value);
            $new_parent_node = Data_Flow_Node::get_for_assignment($type, $var_location);
            $statements_analyzer->data_flow_graph->add_node($new_parent_node);
            $statements_analyzer->node_data->set_type($stmt, $result_type->set_parent_nodes([$new_parent_node->id => $new_parent_node]));
            if ($stmt_value_type && $stmt_value_type->parent_nodes) {
                foreach ($stmt_value_type->parent_nodes as $parent_node) {
                    $statements_analyzer->data_flow_graph->add_path($parent_node, $new_parent_node, $type);
                }
            }
        }
    }
}