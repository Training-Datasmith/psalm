<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression;

use Php_Parser;
use Php_Parser\Node\Expr;
use Php_Parser\Node\Interpolated_String_Part;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Codebase\Taint_Flow_Graph;
use Psalm\Internal\Data_Flow\Data_Flow_Node;
use Psalm\Internal\Data_Flow\Taint_Source;
use Psalm\Plugin\Event_Handler\Event\Add_Remove_Taints_Event;
use Psalm\Type;
use Psalm\Type\Atomic\T_Literal_Float;
use Psalm\Type\Atomic\T_Literal_Int;
use Psalm\Type\Atomic\T_Literal_String;
use Psalm\Type\Atomic\T_Non_Empty_Nonspecific_Literal_String;
use Psalm\Type\Atomic\T_Non_Empty_String;
use Psalm\Type\Atomic\T_Nonspecific_Literal_Int;
use Psalm\Type\Atomic\T_Nonspecific_Literal_String;
use Psalm\Type\Atomic\T_String;
use Psalm\Type\Union;
use function array_diff;
use function in_array;
/**
 * @internal
 */
final class Encapsulated_String_Analyzer
{
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Scalar\Interpolated_String $stmt, Context $context): bool
    {
        $parent_nodes = [];
        $non_empty = false;
        $all_literals = true;
        $literal_string = "";
        foreach ($stmt->parts as $part) {
            if ($part instanceof Expr) {
                if (Expression_Analyzer::analyze($statements_analyzer, $part, $context) === false) {
                    return false;
                }
            }
            if ($part instanceof Interpolated_String_Part) {
                if ($literal_string !== null) {
                    $literal_string .= $part->value;
                }
                $non_empty = $non_empty || $part->value !== "";
            } elseif ($part_type = $statements_analyzer->node_data->get_type($part)) {
                $casted_part_type = Cast_Analyzer::cast_string_attempt($statements_analyzer, $context, $part_type, $part);
                if (!$casted_part_type->all_literals()) {
                    $all_literals = false;
                } elseif (!$non_empty) {
                    // Check if all literals are nonempty
                    $non_empty = true;
                    foreach ($casted_part_type->get_atomic_types() as $atomic_literal) {
                        if (!$atomic_literal instanceof T_Literal_Int && !$atomic_literal instanceof T_Nonspecific_Literal_Int && !$atomic_literal instanceof T_Literal_Float && !$atomic_literal instanceof T_Non_Empty_Nonspecific_Literal_String && !($atomic_literal instanceof T_Literal_String && $atomic_literal->value !== "")) {
                            $non_empty = false;
                            break;
                        }
                    }
                }
                if ($literal_string !== null) {
                    if ($casted_part_type->is_single_literal()) {
                        $literal_string .= $casted_part_type->get_single_literal()->value;
                    } else {
                        $literal_string = null;
                    }
                }
                if ($statements_analyzer->data_flow_graph && !in_array('TaintedInput', $statements_analyzer->get_suppressed_issues())) {
                    $var_location = new Code_Location($statements_analyzer, $part);
                    $new_parent_node = Data_Flow_Node::get_for_assignment('concat', $var_location);
                    $statements_analyzer->data_flow_graph->add_node($new_parent_node);
                    $parent_nodes[$new_parent_node->id] = $new_parent_node;
                    $codebase = $statements_analyzer->get_codebase();
                    $event = new Add_Remove_Taints_Event($stmt, $context, $statements_analyzer, $codebase);
                    $added_taints = $codebase->config->event_dispatcher->dispatch_add_taints($event);
                    $removed_taints = $codebase->config->event_dispatcher->dispatch_remove_taints($event);
                    $taints = array_diff($added_taints, $removed_taints);
                    if ($taints !== [] && $statements_analyzer->data_flow_graph instanceof Taint_Flow_Graph) {
                        $taint_source = Taint_Source::from_node($new_parent_node);
                        $taint_source->taints = $taints;
                        $statements_analyzer->data_flow_graph->add_source($taint_source);
                    }
                    foreach ($casted_part_type->parent_nodes as $parent_node) {
                        $statements_analyzer->data_flow_graph->add_path($parent_node, $new_parent_node, 'concat', $added_taints, $removed_taints);
                    }
                }
            } else {
                $all_literals = false;
                $literal_string = null;
            }
        }
        if ($non_empty) {
            if ($literal_string !== null) {
                $stmt_type = new Union([Type::get_atomic_string_from_literal($literal_string)], ['parent_nodes' => $parent_nodes]);
            } elseif ($all_literals) {
                $stmt_type = new Union([new T_Non_Empty_Nonspecific_Literal_String()], ['parent_nodes' => $parent_nodes]);
            } else {
                $stmt_type = new Union([new T_Non_Empty_String()], ['parent_nodes' => $parent_nodes]);
            }
        } elseif ($all_literals) {
            $stmt_type = new Union([new T_Nonspecific_Literal_String()], ['parent_nodes' => $parent_nodes]);
        } else {
            $stmt_type = new Union([new T_String()], ['parent_nodes' => $parent_nodes]);
        }
        $statements_analyzer->node_data->set_type($stmt, $stmt_type);
        return true;
    }
}