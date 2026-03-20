<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Internal\Analyzer\Function_Like_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Binary_Op\And_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Binary_Op\Coalesce_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Binary_Op\Concat_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Binary_Op\Non_Comparison_Op_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Binary_Op\Or_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Codebase\Taint_Flow_Graph;
use Psalm\Internal\Codebase\Variable_Use_Graph;
use Psalm\Internal\Data_Flow\Data_Flow_Node;
use Psalm\Internal\Data_Flow\Taint_Source;
use Psalm\Internal\Method_Identifier;
use Psalm\Issue\Docblock_Type_Contradiction;
use Psalm\Issue\Impure_Method_Call;
use Psalm\Issue\Invalid_Operand;
use Psalm\Issue\Redundant_Condition;
use Psalm\Issue\Redundant_Condition_Given_Docblock_Type;
use Psalm\Issue\Type_Does_Not_Contain_Type;
use Psalm\Issue_Buffer;
use Psalm\Plugin\Event_Handler\Event\Add_Remove_Taints_Event;
use Psalm\Type;
use Psalm\Type\Atomic\T_Literal_Int;
use Psalm\Type\Atomic\T_Literal_String;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Union;
use UnexpectedValueException;
use function array_diff;
use function in_array;
use function strlen;
/**
 * @internal
 */
final class Binary_Op_Analyzer
{
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Binary_Op $stmt, Context $context, int $nesting = 0, bool $from_stmt = false): bool
    {
        if ($stmt instanceof Php_Parser\Node\Expr\Binary_Op\Concat && $nesting > 100) {
            $statements_analyzer->node_data->set_type($stmt, Type::get_string());
            // ignore deeply-nested string concatenation
            return true;
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Binary_Op\Boolean_And || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Logical_And) {
            $was_inside_general_use = $context->inside_general_use;
            $context->inside_general_use = true;
            $expr_result = And_Analyzer::analyze($statements_analyzer, $stmt, $context, $from_stmt);
            $context->inside_general_use = $was_inside_general_use;
            $statements_analyzer->node_data->set_type($stmt, Type::get_bool());
            return $expr_result;
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Binary_Op\Boolean_Or || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Logical_Or) {
            $was_inside_general_use = $context->inside_general_use;
            $context->inside_general_use = true;
            $expr_result = Or_Analyzer::analyze($statements_analyzer, $stmt, $context, $from_stmt);
            $context->inside_general_use = $was_inside_general_use;
            $statements_analyzer->node_data->set_type($stmt, Type::get_bool());
            return $expr_result;
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Binary_Op\Coalesce) {
            $expr_result = Coalesce_Analyzer::analyze($statements_analyzer, $stmt, $context);
            self::add_data_flow($statements_analyzer, $stmt, $stmt->left, $stmt->right, 'coalesce');
            return $expr_result;
        }
        if ($stmt->left instanceof Php_Parser\Node\Expr\Binary_Op) {
            if (self::analyze($statements_analyzer, $stmt->left, $context, $nesting + 1) === false) {
                return false;
            }
        } else if (Expression_Analyzer::analyze($statements_analyzer, $stmt->left, $context) === false) {
            return false;
        }
        if ($stmt->right instanceof Php_Parser\Node\Expr\Binary_Op) {
            if (self::analyze($statements_analyzer, $stmt->right, $context, $nesting + 1) === false) {
                return false;
            }
        } else if (Expression_Analyzer::analyze($statements_analyzer, $stmt->right, $context) === false) {
            return false;
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Binary_Op\Concat) {
            $stmt_type = Type::get_string();
            Concat_Analyzer::analyze($statements_analyzer, $stmt->left, $stmt->right, $context, $result_type);
            if ($result_type) {
                $stmt_type = $result_type;
            }
            if ($statements_analyzer->data_flow_graph && ($statements_analyzer->data_flow_graph instanceof Variable_Use_Graph || !in_array('TaintedInput', $statements_analyzer->get_suppressed_issues()))) {
                $stmt_left_type = $statements_analyzer->node_data->get_type($stmt->left);
                $stmt_right_type = $statements_analyzer->node_data->get_type($stmt->right);
                $var_location = new Code_Location($statements_analyzer, $stmt);
                $new_parent_node = Data_Flow_Node::get_for_assignment('concat', $var_location);
                $statements_analyzer->data_flow_graph->add_node($new_parent_node);
                $stmt_type = $stmt_type->set_parent_nodes([$new_parent_node->id => $new_parent_node]);
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
                if ($stmt_left_type && $stmt_left_type->parent_nodes) {
                    foreach ($stmt_left_type->parent_nodes as $parent_node) {
                        $statements_analyzer->data_flow_graph->add_path($parent_node, $new_parent_node, 'concat', $added_taints, $removed_taints);
                    }
                }
                if ($stmt_right_type && $stmt_right_type->parent_nodes) {
                    foreach ($stmt_right_type->parent_nodes as $parent_node) {
                        $statements_analyzer->data_flow_graph->add_path($parent_node, $new_parent_node, 'concat', $added_taints, $removed_taints);
                    }
                }
            }
            $statements_analyzer->node_data->set_type($stmt, $stmt_type);
            return true;
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Binary_Op\Spaceship) {
            $statements_analyzer->node_data->set_type($stmt, new Union([new T_Literal_Int(-1), new T_Literal_Int(0), new T_Literal_Int(1)]));
            self::add_data_flow($statements_analyzer, $stmt, $stmt->left, $stmt->right, '<=>');
            return true;
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Binary_Op\Equal || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Not_Equal || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Identical || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Not_Identical || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Greater || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Greater_Or_Equal || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Smaller || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Smaller_Or_Equal) {
            $statements_analyzer->node_data->set_type($stmt, Type::get_bool());
            $stmt_left_type = $statements_analyzer->node_data->get_type($stmt->left);
            $stmt_right_type = $statements_analyzer->node_data->get_type($stmt->right);
            if (($stmt instanceof Php_Parser\Node\Expr\Binary_Op\Greater || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Greater_Or_Equal || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Smaller || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Smaller_Or_Equal) && $statements_analyzer->get_codebase()->config->strict_binary_operands && $stmt_left_type && $stmt_right_type && ($stmt_left_type->is_single() && $stmt_left_type->has_bool() || $stmt_right_type->is_single() && $stmt_right_type->has_bool())) {
                Issue_Buffer::maybe_add(new Invalid_Operand('Cannot compare ' . $stmt_left_type->get_id() . ' to ' . $stmt_right_type->get_id(), new Code_Location($statements_analyzer, $stmt)), $statements_analyzer->get_suppressed_issues());
            }
            if (($stmt instanceof Php_Parser\Node\Expr\Binary_Op\Equal || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Not_Equal || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Identical || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Not_Identical) && $stmt->left instanceof Php_Parser\Node\Expr\Func_Call && $stmt->left->name instanceof Php_Parser\Node\Name && $stmt->left->name->get_parts() === ['substr'] && isset($stmt->left->get_args()[1]) && $stmt_right_type && $stmt_right_type->has_literal_string()) {
                $from_type = $statements_analyzer->node_data->get_type($stmt->left->get_args()[1]->value);
                $length_type = isset($stmt->left->get_args()[2]) ? $statements_analyzer->node_data->get_type($stmt->left->get_args()[2]->value) ?? Type::get_mixed() : null;
                $string_length = null;
                if ($from_type && $from_type->is_single_int_literal() && $length_type === null) {
                    $string_length = -$from_type->get_single_int_literal()->value;
                } elseif ($length_type && $length_type->is_single_int_literal()) {
                    $string_length = $length_type->get_single_int_literal()->value;
                }
                if ($string_length > 0) {
                    foreach ($stmt_right_type->get_atomic_types() as $atomic_right_type) {
                        if ($atomic_right_type instanceof T_Literal_String) {
                            if (strlen($atomic_right_type->value) !== $string_length) {
                                if ($stmt instanceof Php_Parser\Node\Expr\Binary_Op\Equal || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Identical) {
                                    if ($atomic_right_type->from_docblock) {
                                        Issue_Buffer::maybe_add(new Docblock_Type_Contradiction($atomic_right_type . ' string length is not ' . $string_length, new Code_Location($statements_analyzer, $stmt), "strlen({$atomic_right_type}) !== {$string_length}"), $statements_analyzer->get_suppressed_issues());
                                    } else {
                                        Issue_Buffer::maybe_add(new Type_Does_Not_Contain_Type($atomic_right_type . ' string length is not ' . $string_length, new Code_Location($statements_analyzer, $stmt), "strlen({$atomic_right_type}) !== {$string_length}"), $statements_analyzer->get_suppressed_issues());
                                    }
                                } else if ($atomic_right_type->from_docblock) {
                                    Issue_Buffer::maybe_add(new Redundant_Condition_Given_Docblock_Type($atomic_right_type . ' string length is never ' . $string_length, new Code_Location($statements_analyzer, $stmt), "strlen({$atomic_right_type}) !== {$string_length}"), $statements_analyzer->get_suppressed_issues());
                                } else {
                                    Issue_Buffer::maybe_add(new Redundant_Condition($atomic_right_type . ' string length is never ' . $string_length, new Code_Location($statements_analyzer, $stmt), "strlen({$atomic_right_type}) !== {$string_length}"), $statements_analyzer->get_suppressed_issues());
                                }
                            }
                        }
                    }
                }
            }
            $codebase = $statements_analyzer->get_codebase();
            if ($stmt instanceof Php_Parser\Node\Expr\Binary_Op\Equal && $stmt_left_type && $stmt_right_type && ($context->mutation_free || $codebase->alter_code)) {
                self::check_for_impure_equality_comparison($statements_analyzer, $stmt, $stmt_left_type, $stmt_right_type);
            }
            self::add_data_flow($statements_analyzer, $stmt, $stmt->left, $stmt->right, 'comparison');
            return true;
        }
        Non_Comparison_Op_Analyzer::analyze($statements_analyzer, $stmt, $context);
        return true;
    }
    public static function add_data_flow(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr $stmt, Php_Parser\Node\Expr $left, Php_Parser\Node\Expr $right, string $type = 'binaryop'): void
    {
        if ($stmt->get_line() === -1) {
            throw new UnexpectedValueException('bad');
        }
        $result_type = $statements_analyzer->node_data->get_type($stmt);
        if (!$result_type) {
            return;
        }
        if ($statements_analyzer->data_flow_graph instanceof Taint_Flow_Graph && $stmt instanceof Php_Parser\Node\Expr\Binary_Op && !$stmt instanceof Php_Parser\Node\Expr\Binary_Op\Concat && !$stmt instanceof Php_Parser\Node\Expr\Binary_Op\Coalesce && (!$stmt instanceof Php_Parser\Node\Expr\Binary_Op\Plus || !$result_type->has_array())) {
            //among BinaryOp, only Concat and Coalesce can pass tainted value to the result. Also Plus on arrays only
            return;
        }
        if ($statements_analyzer->data_flow_graph) {
            $stmt_left_type = $statements_analyzer->node_data->get_type($left);
            $stmt_right_type = $statements_analyzer->node_data->get_type($right);
            $var_location = new Code_Location($statements_analyzer, $stmt);
            $new_parent_node = Data_Flow_Node::get_for_assignment($type, $var_location);
            $statements_analyzer->data_flow_graph->add_node($new_parent_node);
            $result_type = $result_type->set_parent_nodes([$new_parent_node->id => $new_parent_node]);
            $statements_analyzer->node_data->set_type($stmt, $result_type);
            if ($stmt_left_type && $stmt_left_type->parent_nodes) {
                foreach ($stmt_left_type->parent_nodes as $parent_node) {
                    $statements_analyzer->data_flow_graph->add_path($parent_node, $new_parent_node, $type);
                }
            }
            if ($stmt_right_type && $stmt_right_type->parent_nodes) {
                foreach ($stmt_right_type->parent_nodes as $parent_node) {
                    $statements_analyzer->data_flow_graph->add_path($parent_node, $new_parent_node, $type);
                }
            }
            if ($stmt instanceof Php_Parser\Node\Expr\Assign_Op && $statements_analyzer->data_flow_graph instanceof Variable_Use_Graph) {
                $root_expr = $left;
                while ($root_expr instanceof Php_Parser\Node\Expr\Array_Dim_Fetch) {
                    $root_expr = $root_expr->var;
                }
                if ($left instanceof Php_Parser\Node\Expr\Property_Fetch) {
                    $statements_analyzer->data_flow_graph->add_path($new_parent_node, new Data_Flow_Node('variable-use', 'variable use', null), 'used-by-instance-property');
                }
                if ($left instanceof Php_Parser\Node\Expr\Static_Property_Fetch) {
                    $statements_analyzer->data_flow_graph->add_path($new_parent_node, new Data_Flow_Node('variable-use', 'variable use', null), 'use-in-static-property');
                } elseif (!$left instanceof Php_Parser\Node\Expr\Variable) {
                    $statements_analyzer->data_flow_graph->add_path($new_parent_node, new Data_Flow_Node('variable-use', 'variable use', null), 'variable-use');
                }
            }
        }
    }
    private static function check_for_impure_equality_comparison(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Binary_Op\Equal $stmt, Union $stmt_left_type, Union $stmt_right_type): void
    {
        $codebase = $statements_analyzer->get_codebase();
        if ($stmt_left_type->has_string() && $stmt_right_type->has_object_type()) {
            foreach ($stmt_right_type->get_atomic_types() as $atomic_type) {
                if ($atomic_type instanceof T_Named_Object) {
                    try {
                        $storage = $codebase->methods->get_storage(new Method_Identifier($atomic_type->value, '__tostring'));
                    } catch (UnexpectedValueException) {
                        continue;
                    }
                    if (!$storage->mutation_free) {
                        if ($statements_analyzer->get_source() instanceof Function_Like_Analyzer && $statements_analyzer->get_source()->track_mutations) {
                            $statements_analyzer->get_source()->inferred_has_mutation = true;
                            $statements_analyzer->get_source()->inferred_impure = true;
                        } else {
                            Issue_Buffer::maybe_add(new Impure_Method_Call('Cannot call a possibly-mutating method ' . $atomic_type->value . '::__toString from a pure context', new Code_Location($statements_analyzer, $stmt)), $statements_analyzer->get_suppressed_issues());
                        }
                    }
                }
            }
        } elseif ($stmt_right_type->has_string() && $stmt_left_type->has_object_type()) {
            foreach ($stmt_left_type->get_atomic_types() as $atomic_type) {
                if ($atomic_type instanceof T_Named_Object) {
                    try {
                        $storage = $codebase->methods->get_storage(new Method_Identifier($atomic_type->value, '__tostring'));
                    } catch (UnexpectedValueException) {
                        continue;
                    }
                    if (!$storage->mutation_free) {
                        if ($statements_analyzer->get_source() instanceof Function_Like_Analyzer && $statements_analyzer->get_source()->track_mutations) {
                            $statements_analyzer->get_source()->inferred_has_mutation = true;
                            $statements_analyzer->get_source()->inferred_impure = true;
                        } else {
                            Issue_Buffer::maybe_add(new Impure_Method_Call('Cannot call a possibly-mutating method ' . $atomic_type->value . '::__toString from a pure context', new Code_Location($statements_analyzer, $stmt)), $statements_analyzer->get_suppressed_issues());
                        }
                    }
                }
            }
        }
    }
}