<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression\Assignment;

use InvalidArgumentException;
use Php_Parser;
use Php_Parser\Node\Expr\Variable;
use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Internal\Analyzer\Class_Like_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Array_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Expression_Identifier;
use Psalm\Internal\Analyzer\Statements\Expression\Fetch\Array_Fetch_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Codebase\Variable_Use_Graph;
use Psalm\Internal\Data_Flow\Data_Flow_Node;
use Psalm\Internal\Type\Template_Inferred_Type_Replacer;
use Psalm\Internal\Type\Template_Result;
use Psalm\Issue\Invalid_Array_Assignment;
use Psalm\Issue_Buffer;
use Psalm\Type;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Class_String_Map;
use Psalm\Type\Atomic\T_Int_Range;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Atomic\T_Literal_Class_String;
use Psalm\Type\Atomic\T_Literal_Int;
use Psalm\Type\Atomic\T_Literal_String;
use Psalm\Type\Atomic\T_Non_Empty_Array;
use Psalm\Type\Atomic\T_String;
use Psalm\Type\Atomic\T_Template_Indexed_Access;
use Psalm\Type\Atomic\T_Template_Key_Of;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Atomic\T_Template_Param_Class;
use Psalm\Type\Union;
use function array_fill;
use function array_pop;
use function array_reverse;
use function array_shift;
use function array_slice;
use function assert;
use function count;
use function end;
use function implode;
use function in_array;
use function is_string;
use function str_contains;
use function strlen;
/**
 * @internal
 */
final class Array_Assignment_Analyzer
{
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Array_Dim_Fetch $stmt, Context $context, ?Php_Parser\Node\Expr $assign_value, Union $assignment_value_type): void
    {
        $nesting = 0;
        $var_id = Expression_Identifier::get_var_id($stmt->var, $statements_analyzer->get_fqcln(), $statements_analyzer, $nesting);
        self::update_array_type($statements_analyzer, $stmt, $assign_value, $assignment_value_type, $context);
        if (!$statements_analyzer->node_data->get_type($stmt->var) && $var_id) {
            $context->vars_in_scope[$var_id] = Type::get_mixed();
        }
    }
    /**
     * @return false|null
     * @psalm-suppress PossiblyUnusedReturnValue not used but seems important
     */
    public static function update_array_type(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Array_Dim_Fetch $stmt, ?Php_Parser\Node\Expr $assign_value, Union $assignment_type, Context $context): ?bool
    {
        $root_array_expr = $stmt;
        $child_stmts = [];
        while ($root_array_expr->var instanceof Php_Parser\Node\Expr\Array_Dim_Fetch) {
            $child_stmts[] = $root_array_expr;
            $root_array_expr = $root_array_expr->var;
        }
        $child_stmts[] = $root_array_expr;
        $root_array_expr = $root_array_expr->var;
        Expression_Analyzer::analyze($statements_analyzer, $root_array_expr, $context, true);
        $codebase = $statements_analyzer->get_codebase();
        $root_type = $statements_analyzer->node_data->get_type($root_array_expr) ?? Type::get_mixed();
        if ($root_type->has_mixed()) {
            Expression_Analyzer::analyze($statements_analyzer, $stmt->var, $context, true);
            if ($stmt->dim) {
                Expression_Analyzer::analyze($statements_analyzer, $stmt->dim, $context);
            }
        }
        $current_type = $root_type;
        $current_dim = $stmt->dim;
        // gets a variable id that *may* contain array keys
        $root_var_id = Expression_Identifier::get_extended_var_id($root_array_expr, $statements_analyzer->get_fqcln(), $statements_analyzer);
        $parent_var_id = null;
        $offset_already_existed = false;
        self::analyze_nested_array_assignment($statements_analyzer, $codebase, $context, $assign_value, $assignment_type, $child_stmts, $root_var_id, $parent_var_id, $root_type, $current_type, $current_dim, $offset_already_existed);
        $root_is_string = $root_type->is_string();
        $key_values = [];
        if ($current_dim instanceof Php_Parser\Node\Scalar\String_) {
            $value_type = Type::get_atomic_string_from_literal($current_dim->value);
            if ($value_type instanceof T_Literal_String) {
                $key_values[] = $value_type;
            }
        } elseif ($current_dim instanceof Php_Parser\Node\Scalar\Int_ && !$root_is_string) {
            $key_values[] = new T_Literal_Int($current_dim->value);
        } elseif ($current_dim && ($key_type = $statements_analyzer->node_data->get_type($current_dim)) && !$root_is_string) {
            $string_literals = $key_type->get_literal_strings();
            $int_literals = $key_type->get_literal_ints();
            $all_atomic_types = $key_type->get_atomic_types();
            if (count($string_literals) + count($int_literals) === count($all_atomic_types)) {
                foreach ($string_literals as $string_literal) {
                    $key_values[] = $string_literal;
                }
                foreach ($int_literals as $int_literal) {
                    $key_values[] = $int_literal;
                }
            }
        }
        if ($key_values) {
            $new_child_type = self::update_type_with_key_values($codebase, $root_type, $current_type, $key_values);
        } elseif (!$root_is_string) {
            $new_child_type = self::update_array_assignment_child_type($statements_analyzer, $codebase, $current_dim, $context, $current_type, $root_type, $offset_already_existed, $parent_var_id);
        } else {
            $new_child_type = $root_type;
        }
        $new_child_type = $new_child_type->get_builder();
        $new_child_type->remove_type('null');
        $new_child_type = $new_child_type->freeze();
        if (!$root_type->has_object_type()) {
            $root_type = $new_child_type;
        }
        $statements_analyzer->node_data->set_type($root_array_expr, $root_type);
        if ($root_array_expr instanceof Php_Parser\Node\Expr\Property_Fetch) {
            if ($root_array_expr->name instanceof Php_Parser\Node\Identifier) {
                Instance_Property_Assignment_Analyzer::analyze($statements_analyzer, $root_array_expr, $root_array_expr->name->name, null, $root_type, $context, false);
            } else {
                if (Expression_Analyzer::analyze($statements_analyzer, $root_array_expr->name, $context) === false) {
                    return false;
                }
                if (Expression_Analyzer::analyze($statements_analyzer, $root_array_expr->var, $context) === false) {
                    return false;
                }
            }
        } elseif ($root_array_expr instanceof Php_Parser\Node\Expr\Static_Property_Fetch && $root_array_expr->name instanceof Php_Parser\Node\Identifier) {
            if (Static_Property_Assignment_Analyzer::analyze($statements_analyzer, $root_array_expr, null, $root_type, $context) === false) {
                return false;
            }
        } elseif ($root_var_id) {
            $context->vars_in_scope[$root_var_id] = $root_type;
        }
        if ($root_array_expr instanceof Php_Parser\Node\Expr\Method_Call || $root_array_expr instanceof Php_Parser\Node\Expr\Static_Call || $root_array_expr instanceof Php_Parser\Node\Expr\Func_Call) {
            if ($root_type->has_array()) {
                Issue_Buffer::maybe_add(new Invalid_Array_Assignment('Assigning to the output of a function has no effect', new Code_Location($statements_analyzer->get_source(), $root_array_expr)), $statements_analyzer->get_suppressed_issues());
            }
        }
        return null;
    }
    /**
     * @param non-empty-list<TLiteralInt|TLiteralString> $key_values
     */
    private static function update_type_with_key_values(Codebase $codebase, Union $child_stmt_type, Union $current_type, array $key_values): Union
    {
        $has_matching_objectlike_property = false;
        $has_matching_string = false;
        $changed = false;
        $types = [];
        foreach ($child_stmt_type->get_atomic_types() as $type) {
            $old_type = $type;
            if ($type instanceof T_Template_Param) {
                $type = $type->replace_as(self::update_type_with_key_values($codebase, $type->as, $current_type, $key_values));
                $has_matching_objectlike_property = true;
            } elseif ($type instanceof T_Keyed_Array) {
                $properties = $type->properties;
                foreach ($key_values as $key_value) {
                    if (isset($properties[$key_value->value])) {
                        $has_matching_objectlike_property = true;
                        $properties[$key_value->value] = $current_type;
                    }
                }
                $type = $type->set_properties($properties);
            } elseif ($type instanceof T_String) {
                foreach ($key_values as $key_value) {
                    if ($key_value instanceof T_Literal_Int) {
                        $has_matching_string = true;
                        if ($type instanceof T_Literal_String && $current_type->is_single_string_literal()) {
                            $new_char = $current_type->get_single_string_literal()->value;
                            if (strlen($new_char) === 1 && $type->value[0] !== $new_char) {
                                $v = $type->value;
                                $v[0] = $new_char;
                                $changed = true;
                                $type = Type::get_atomic_string_from_literal($v);
                                break;
                            }
                        }
                    }
                }
            }
            $types[$type->get_key()] = $type;
            $changed = $changed || $old_type !== $type;
        }
        if ($changed) {
            $child_stmt_type = $child_stmt_type->get_builder()->set_types($types)->freeze();
        }
        if (!$has_matching_objectlike_property && !$has_matching_string) {
            $properties = [];
            $class_strings = [];
            $current_type = $current_type->set_possibly_undefined($current_type->possibly_undefined || count($key_values) > 1);
            foreach ($key_values as $key_value) {
                $properties[$key_value->value] = $current_type;
                if ($key_value instanceof T_Literal_Class_String) {
                    $class_strings[$key_value->value] = true;
                }
            }
            $object_like = new T_Keyed_Array($properties, $class_strings ?: null);
            $array_assignment_type = new Union([$object_like]);
            return Type::combine_union_types($child_stmt_type, $array_assignment_type, $codebase, true, false);
        }
        return $child_stmt_type;
    }
    /**
     * @param list<TLiteralInt|TLiteralString> $key_values $key_values
     */
    private static function taint_array_assignment(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Array_Dim_Fetch $expr, Union &$stmt_type, Union $child_stmt_type, ?string $var_var_id, array $key_values): void
    {
        if ($statements_analyzer->data_flow_graph && ($statements_analyzer->data_flow_graph instanceof Variable_Use_Graph || !in_array('TaintedInput', $statements_analyzer->get_suppressed_issues()))) {
            $var_location = new Code_Location($statements_analyzer->get_source(), $expr->var);
            $parent_node = Data_Flow_Node::get_for_assignment($var_var_id ?: 'assignment', $var_location);
            $statements_analyzer->data_flow_graph->add_node($parent_node);
            $old_parent_nodes = $stmt_type->parent_nodes;
            $stmt_type = $stmt_type->set_parent_nodes([$parent_node->id => $parent_node]);
            foreach ($old_parent_nodes as $old_parent_node) {
                $statements_analyzer->data_flow_graph->add_path($old_parent_node, $parent_node, '=');
                if ($stmt_type->by_ref) {
                    $statements_analyzer->data_flow_graph->add_path($parent_node, $old_parent_node, '=');
                }
            }
            foreach ($stmt_type->parent_nodes as $parent_node) {
                foreach ($child_stmt_type->parent_nodes as $child_parent_node) {
                    if ($key_values) {
                        foreach ($key_values as $key_value) {
                            $statements_analyzer->data_flow_graph->add_path($child_parent_node, $parent_node, 'arrayvalue-assignment-\'' . $key_value->value . '\'');
                        }
                    } else {
                        $statements_analyzer->data_flow_graph->add_path($child_parent_node, $parent_node, 'arrayvalue-assignment');
                    }
                }
            }
        }
    }
    private static function update_array_assignment_child_type(Statements_Analyzer $statements_analyzer, Codebase $codebase, ?Php_Parser\Node\Expr $current_dim, Context $context, Union $value_type, Union $root_type, bool $offset_already_existed, ?string $parent_var_id): Union
    {
        $templated_assignment = false;
        $array_atomic_type_class_string = null;
        $array_atomic_type_array = null;
        $array_atomic_type_list = null;
        if ($current_dim) {
            $key_type = $statements_analyzer->node_data->get_type($current_dim);
            if ($key_type) {
                if ($key_type->has_mixed()) {
                    $key_type = Type::get_array_key();
                }
                if ($key_type->is_single()) {
                    $key_type_type = $key_type->get_single_atomic();
                    if ($key_type_type instanceof T_Int_Range && $key_type_type->dependent_list_key === $parent_var_id) {
                        $offset_already_existed = true;
                    }
                    if ($key_type_type instanceof T_Template_Param && $key_type_type->as->is_single() && $root_type->is_single() && $value_type->is_single()) {
                        $key_type_as_type = $key_type_type->as->get_single_atomic();
                        $value_atomic_type = $value_type->get_single_atomic();
                        $root_atomic_type = $root_type->get_single_atomic();
                        if ($key_type_as_type instanceof T_Template_Key_Of && $root_atomic_type instanceof T_Template_Param && $value_atomic_type instanceof T_Template_Indexed_Access && $key_type_as_type->param_name === $root_atomic_type->param_name && $key_type_as_type->defining_class === $root_atomic_type->defining_class && $value_atomic_type->array_param_name === $root_atomic_type->param_name && $value_atomic_type->offset_param_name === $key_type_type->param_name && $value_atomic_type->defining_class === $root_atomic_type->defining_class) {
                            $templated_assignment = true;
                            $offset_already_existed = true;
                        }
                    }
                }
                $array_atomic_key_type = Array_Fetch_Analyzer::replace_offset_type_with_ints($key_type);
            } else {
                $array_atomic_key_type = Type::get_array_key();
            }
            if ($parent_var_id && $parent_type = $context->vars_in_scope[$parent_var_id] ?? null) {
                if ($offset_already_existed && $parent_type->has_list() && !str_contains($parent_var_id, '[')) {
                    $array_atomic_type_list = $value_type;
                } elseif ($parent_type->has_class_string_map() && $key_type && $key_type->is_templated_class_string()) {
                    /**
                     * @var TClassStringMap
                     */
                    $class_string_map = $parent_type->get_array();
                    /**
                     * @var TTemplateParamClass
                     */
                    $offset_type_part = $key_type->get_single_atomic();
                    $template_result = new Template_Result([], [$offset_type_part->param_name => [$offset_type_part->defining_class => new Union([new T_Template_Param($class_string_map->param_name, $offset_type_part->as_type ? new Union([$offset_type_part->as_type]) : Type::get_object(), 'class-string-map')])]]);
                    $value_type = Template_Inferred_Type_Replacer::replace($value_type, $template_result, $codebase);
                    $array_atomic_type_class_string = new T_Class_String_Map($class_string_map->param_name, $class_string_map->as_type, $value_type);
                } else {
                    $array_atomic_type_array = [$array_atomic_key_type, $value_type];
                }
            } else {
                $array_atomic_type_array = [$array_atomic_key_type, $value_type];
            }
        } else {
            $array_atomic_type_list = $value_type;
        }
        $from_countable_object_like = false;
        $array_atomic_type = null;
        if (!$current_dim && !$context->inside_loop) {
            $atomic_root_types = $root_type->get_atomic_types();
            if (isset($atomic_root_types['array'])) {
                $atomic_root_type_array = $atomic_root_types['array'];
                if ($array_atomic_type_class_string) {
                    $array_atomic_type = new T_Non_Empty_Array([$array_atomic_type_class_string->get_standin_key_param(), $array_atomic_type_class_string->value_param]);
                } elseif ($atomic_root_type_array instanceof T_Keyed_Array && $atomic_root_type_array->is_list && $atomic_root_type_array->fallback_params === null) {
                    $array_atomic_type = $atomic_root_type_array;
                } elseif ($atomic_root_type_array instanceof T_Non_Empty_Array || $atomic_root_type_array instanceof T_Keyed_Array && $atomic_root_type_array->is_list && $atomic_root_type_array->is_non_empty()) {
                    $prop_count = null;
                    if ($atomic_root_type_array instanceof T_Non_Empty_Array) {
                        $prop_count = $atomic_root_type_array->count;
                    } else {
                        $min_count = $atomic_root_type_array->get_min_count();
                        if ($min_count === $atomic_root_type_array->get_max_count()) {
                            $prop_count = $min_count;
                        }
                    }
                    if ($array_atomic_type_array) {
                        $array_atomic_type = new T_Non_Empty_Array($array_atomic_type_array, $prop_count);
                    } elseif ($prop_count !== null) {
                        assert($array_atomic_type_list !== null);
                        $array_atomic_type = new T_Keyed_Array(array_fill(0, $prop_count, $array_atomic_type_list), null, [Type::get_list_key(), $array_atomic_type_list], true);
                    }
                } elseif ($atomic_root_type_array instanceof T_Keyed_Array && $atomic_root_type_array->fallback_params === null) {
                    if ($array_atomic_type_array) {
                        $array_atomic_type = new T_Non_Empty_Array($array_atomic_type_array, count($atomic_root_type_array->properties));
                    } else {
                        assert($array_atomic_type_list !== null);
                        $array_atomic_type = array_fill($atomic_root_type_array->get_min_count(), count($atomic_root_type_array->properties) - 1, $array_atomic_type_list);
                        assert(count($array_atomic_type) > 0);
                        $array_atomic_type = new T_Keyed_Array($array_atomic_type, null, null, true);
                    }
                    $from_countable_object_like = true;
                } elseif ($array_atomic_type_list) {
                    $array_atomic_type = Type::get_non_empty_list_atomic($array_atomic_type_list);
                } else {
                    assert($array_atomic_type_array !== null);
                    $array_atomic_type = new T_Non_Empty_Array($array_atomic_type_array);
                }
            }
        }
        $array_atomic_type ??= $array_atomic_type_class_string ?? ($array_atomic_type_list !== null ? Type::get_non_empty_list_atomic($array_atomic_type_list) : null) ?? ($array_atomic_type_array !== null ? new T_Non_Empty_Array($array_atomic_type_array) : null);
        assert($array_atomic_type !== null);
        $array_assignment_type = new Union([$array_atomic_type]);
        if ($templated_assignment) {
            $new_child_type = $root_type;
        } else {
            $new_child_type = Type::combine_union_types($root_type, $array_assignment_type, $codebase, true, true);
        }
        if ($from_countable_object_like) {
            $atomic_root_types = $new_child_type->get_atomic_types();
            if (isset($atomic_root_types['array'])) {
                $atomic_root_type_array = $atomic_root_types['array'];
                if ($atomic_root_type_array instanceof T_Non_Empty_Array && $atomic_root_type_array->count !== null) {
                    $atomic_root_types['array'] = $atomic_root_type_array->set_count($atomic_root_type_array->count + 1);
                    $new_child_type = new Union($atomic_root_types);
                } elseif ($atomic_root_type_array instanceof T_Keyed_Array && $atomic_root_type_array->is_list) {
                    $properties = $atomic_root_type_array->properties;
                    $had_undefined = false;
                    foreach ($properties as &$property) {
                        if ($property->possibly_undefined) {
                            $property = $property->set_possibly_undefined(true);
                            $had_undefined = true;
                            break;
                        }
                    }
                    if (!$had_undefined && $atomic_root_type_array->fallback_params) {
                        $properties[] = $atomic_root_type_array->fallback_params[1];
                    }
                    $atomic_root_types['array'] = $atomic_root_type_array->set_properties($properties);
                    $new_child_type = new Union($atomic_root_types);
                }
            }
        }
        return $new_child_type;
    }
    /**
     * @param  non-empty-list<PhpParser\Node\Expr\ArrayDimFetch>  $child_stmts
     * @param-out PhpParser\Node\Expr $child_stmt
     */
    private static function analyze_nested_array_assignment(Statements_Analyzer $statements_analyzer, Codebase $codebase, Context $context, ?Php_Parser\Node\Expr $assign_value, Union $assignment_type, array $child_stmts, ?string $root_var_id, ?string &$parent_var_id, Union &$root_type, Union &$current_type, ?Php_Parser\Node\Expr &$current_dim, bool &$offset_already_existed): void
    {
        $var_id_additions = [];
        $root_var = end($child_stmts)->var;
        // First go from the root element up, and go as far as we can to figure out what
        // array types there are
        foreach (array_reverse($child_stmts) as $i => $child_stmt) {
            $child_stmt_dim_type = null;
            $offset_type = null;
            if ($child_stmt->dim) {
                $was_inside_general_use = $context->inside_general_use;
                $context->inside_general_use = true;
                if (Expression_Analyzer::analyze($statements_analyzer, $child_stmt->dim, $context) === false) {
                    $context->inside_general_use = $was_inside_general_use;
                    return;
                }
                $context->inside_general_use = $was_inside_general_use;
                if (!$child_stmt_dim_type = $statements_analyzer->node_data->get_type($child_stmt->dim)) {
                    return;
                }
                [$offset_type, $var_id_addition, $full_var_id] = self::get_array_assignment_offset_type($statements_analyzer, $child_stmt, $child_stmt_dim_type);
                $var_id_additions[] = $var_id_addition;
            } else {
                $var_id_additions[] = '';
                $full_var_id = false;
            }
            if (!$array_type = $statements_analyzer->node_data->get_type($child_stmt->var)) {
                return;
            }
            if ($array_type->is_never()) {
                $array_type = Type::get_empty_array();
                $statements_analyzer->node_data->set_type($child_stmt->var, $array_type);
            }
            $extended_var_id = $root_var_id . implode('', $var_id_additions);
            if ($parent_var_id && isset($context->vars_in_scope[$parent_var_id])) {
                $array_type = $context->vars_in_scope[$parent_var_id];
                $statements_analyzer->node_data->set_type($child_stmt->var, $array_type);
            }
            $is_last = $i === count($child_stmts) - 1;
            $child_stmt_dim_type_or_int = $child_stmt_dim_type ?? Type::get_int();
            $child_stmt_type = Array_Fetch_Analyzer::get_array_access_type_given_offset($statements_analyzer, $child_stmt, $array_type, $child_stmt_dim_type_or_int, true, $extended_var_id, $context, $assign_value, !$is_last ? null : $assignment_type);
            if ($child_stmt->dim) {
                $statements_analyzer->node_data->set_type($child_stmt->dim, $child_stmt_dim_type_or_int);
            }
            $statements_analyzer->node_data->set_type($child_stmt, $child_stmt_type);
            if ($is_last) {
                // we need this slight hack as the type we're putting it has to be
                // different from the type we're getting out
                if ($array_type->is_single() && $array_type->has_class_string_map()) {
                    $assignment_type = $child_stmt_type;
                }
                $child_stmt_type = $assignment_type;
                $statements_analyzer->node_data->set_type($child_stmt, $assignment_type);
                if ($statements_analyzer->data_flow_graph) {
                    self::taint_array_assignment($statements_analyzer, $child_stmt, $array_type, $assignment_type, Expression_Identifier::get_extended_var_id($child_stmt->var, $statements_analyzer->get_fqcln(), $statements_analyzer), $offset_type !== null ? [$offset_type] : []);
                }
            }
            $statements_analyzer->node_data->set_type($child_stmt->var, $array_type);
            if ($root_var_id) {
                if (!$parent_var_id) {
                    $rooted_parent_id = $root_var_id;
                    $root_type = $array_type;
                } else {
                    $rooted_parent_id = $parent_var_id;
                }
                $context->vars_in_scope[$rooted_parent_id] = $array_type;
                $context->possibly_assigned_var_ids[$rooted_parent_id] = true;
            }
            $current_type = $child_stmt_type;
            $current_dim = $child_stmt->dim;
            $parent_var_id = $extended_var_id;
        }
        if ($statements_analyzer->data_flow_graph instanceof Variable_Use_Graph && $root_var_id !== null && isset($context->references_to_external_scope[$root_var_id]) && $root_var instanceof Variable && is_string($root_var->name) && $root_var_id === '$' . $root_var->name) {
            // Array is a reference to an external scope, mark it as used
            $statements_analyzer->data_flow_graph->add_path(Data_Flow_Node::get_for_assignment($root_var_id, new Code_Location($statements_analyzer->get_source(), $root_var)), new Data_Flow_Node('variable-use', 'variable use', null), 'variable-use');
        }
        if ($root_var_id && $full_var_id && ($child_stmt_var_type = $statements_analyzer->node_data->get_type($child_stmt->var)) && !$child_stmt_var_type->has_object_type()) {
            $extended_var_id = $root_var_id . implode('', $var_id_additions);
            $parent_var_id = $root_var_id . implode('', array_slice($var_id_additions, 0, -1));
            if (isset($context->vars_in_scope[$extended_var_id]) && !$context->vars_in_scope[$extended_var_id]->possibly_undefined) {
                $offset_already_existed = true;
            }
            $context->vars_in_scope[$extended_var_id] = $assignment_type;
            $context->possibly_assigned_var_ids[$extended_var_id] = true;
        }
        array_shift($child_stmts);
        // only update as many child stmts are we were able to process above
        foreach ($child_stmts as $child_stmt) {
            $child_stmt_type = $statements_analyzer->node_data->get_type($child_stmt);
            if (!$child_stmt_type) {
                throw new InvalidArgumentException('Should never get here');
            }
            $key_values = $current_dim ? self::get_dim_key_values($statements_analyzer, $current_dim) : [];
            if ($key_values) {
                $new_child_type = self::update_type_with_key_values($codebase, $child_stmt_type, $current_type, $key_values);
            } else {
                if (!$current_dim) {
                    $array_assignment_type = Type::get_list($current_type);
                } else {
                    $key_type = $statements_analyzer->node_data->get_type($current_dim);
                    $array_assignment_type = new Union([new T_Array([$key_type && !$key_type->has_mixed() ? $key_type : Type::get_array_key(), $current_type])]);
                }
                $new_child_type = Type::combine_union_types($child_stmt_type, $array_assignment_type, $codebase, true, true);
            }
            if ($new_child_type->has_null() || $new_child_type->possibly_undefined) {
                $new_child_type = $new_child_type->get_builder();
                $new_child_type->remove_type('null');
                $new_child_type->possibly_undefined = false;
                $new_child_type = $new_child_type->freeze();
            }
            if (!$child_stmt_type->has_object_type()) {
                $child_stmt_type = $new_child_type;
                $statements_analyzer->node_data->set_type($child_stmt, $new_child_type);
            }
            $current_type = $child_stmt_type;
            $current_dim = $child_stmt->dim;
            array_pop($var_id_additions);
            $parent_array_var_id = null;
            if ($root_var_id) {
                $extended_var_id = $root_var_id . implode('', $var_id_additions);
                $parent_array_var_id = $root_var_id . implode('', array_slice($var_id_additions, 0, -1));
                $context->vars_in_scope[$extended_var_id] = $child_stmt_type;
                $context->possibly_assigned_var_ids[$extended_var_id] = true;
            }
            if ($statements_analyzer->data_flow_graph) {
                $t_orig = $statements_analyzer->node_data->get_type($child_stmt->var);
                $array_type = $t_orig ?? Type::get_mixed();
                self::taint_array_assignment($statements_analyzer, $child_stmt, $array_type, $new_child_type, $parent_array_var_id, $child_stmt->dim ? self::get_dim_key_values($statements_analyzer, $child_stmt->dim) : []);
                if ($t_orig) {
                    $statements_analyzer->node_data->set_type($child_stmt->var, $array_type);
                }
                if ($root_var_id) {
                    if ($parent_array_var_id === $root_var_id) {
                        $rooted_parent_id = $root_var_id;
                        $root_type = $array_type;
                    } else {
                        assert($parent_array_var_id !== null);
                        $rooted_parent_id = $parent_array_var_id;
                    }
                    $context->vars_in_scope[$rooted_parent_id] = $array_type;
                }
            }
        }
    }
    /**
     * @return list<TLiteralInt|TLiteralString>
     */
    private static function get_dim_key_values(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr $dim): array
    {
        $key_values = [];
        if ($dim instanceof Php_Parser\Node\Scalar\String_) {
            $value_type = Type::get_atomic_string_from_literal($dim->value);
            if ($value_type instanceof T_Literal_String) {
                $key_values[] = $value_type;
            }
        } elseif ($dim instanceof Php_Parser\Node\Scalar\Int_) {
            $key_values[] = new T_Literal_Int($dim->value);
        } else {
            $key_type = $statements_analyzer->node_data->get_type($dim);
            if ($key_type) {
                $string_literals = $key_type->get_literal_strings();
                $int_literals = $key_type->get_literal_ints();
                $all_atomic_types = $key_type->get_atomic_types();
                if (count($string_literals) + count($int_literals) === count($all_atomic_types)) {
                    foreach ($string_literals as $string_literal) {
                        $key_values[] = $string_literal;
                    }
                    foreach ($int_literals as $int_literal) {
                        $key_values[] = $int_literal;
                    }
                }
            }
        }
        return $key_values;
    }
    /**
     * @return array{TLiteralInt|TLiteralString|null, string, bool}
     */
    private static function get_array_assignment_offset_type(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Array_Dim_Fetch $child_stmt, Union $child_stmt_dim_type): array
    {
        if ($child_stmt->dim instanceof Php_Parser\Node\Scalar\String_ || ($child_stmt->dim instanceof Php_Parser\Node\Expr\Const_Fetch || $child_stmt->dim instanceof Php_Parser\Node\Expr\Class_Const_Fetch) && $child_stmt_dim_type->is_single_string_literal()) {
            if ($child_stmt->dim instanceof Php_Parser\Node\Scalar\String_) {
                $offset_type = Type::get_atomic_string_from_literal($child_stmt->dim->value);
                if (!$offset_type instanceof T_Literal_String) {
                    return [null, '[string]', false];
                }
            } else {
                $offset_type = $child_stmt_dim_type->get_single_string_literal();
            }
            $string_to_int = Array_Analyzer::get_literal_array_key_int($offset_type->value);
            if ($string_to_int !== false) {
                $var_id_addition = '[' . $string_to_int . ']';
            } else {
                $var_id_addition = '[\'' . $offset_type->value . '\']';
            }
            return [$offset_type, $var_id_addition, true];
        }
        if ($child_stmt->dim instanceof Php_Parser\Node\Scalar\Int_ || ($child_stmt->dim instanceof Php_Parser\Node\Expr\Const_Fetch || $child_stmt->dim instanceof Php_Parser\Node\Expr\Class_Const_Fetch) && $child_stmt_dim_type->is_single_int_literal()) {
            if ($child_stmt->dim instanceof Php_Parser\Node\Scalar\Int_) {
                $offset_type = new T_Literal_Int($child_stmt->dim->value);
            } else {
                $offset_type = $child_stmt_dim_type->get_single_int_literal();
            }
            $var_id_addition = '[' . $offset_type->value . ']';
            return [$offset_type, $var_id_addition, true];
        }
        if ($child_stmt->dim instanceof Php_Parser\Node\Expr\Variable && is_string($child_stmt->dim->name)) {
            $var_id_addition = '[$' . $child_stmt->dim->name . ']';
            return [null, $var_id_addition, true];
        }
        if ($child_stmt->dim instanceof Php_Parser\Node\Expr\Property_Fetch && $child_stmt->dim->name instanceof Php_Parser\Node\Identifier) {
            $object_id = Expression_Identifier::get_extended_var_id($child_stmt->dim->var, $statements_analyzer->get_fqcln(), $statements_analyzer);
            if ($object_id) {
                $var_id_addition = '[' . $object_id . '->' . $child_stmt->dim->name->name . ']';
            } else {
                $var_id_addition = '[' . $child_stmt_dim_type . ']';
            }
            return [null, $var_id_addition, true];
        }
        if ($child_stmt->dim instanceof Php_Parser\Node\Expr\Class_Const_Fetch && $child_stmt->dim->name instanceof Php_Parser\Node\Identifier && $child_stmt->dim->class instanceof Php_Parser\Node\Name) {
            $object_name = Class_Like_Analyzer::get_fqcln_from_name_object($child_stmt->dim->class, $statements_analyzer->get_aliases());
            $var_id_addition = '[' . $object_name . '::' . $child_stmt->dim->name->name . ']';
            return [null, $var_id_addition, true];
        }
        $var_id_addition = '[' . $child_stmt_dim_type . ']';
        return [null, $var_id_addition, false];
    }
}