<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression\Call;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Internal\Analyzer\Function_Like_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Assertion_Finder;
use Psalm\Internal\Analyzer\Statements\Expression\Expression_Identifier;
use Psalm\Internal\Analyzer\Statements\Expression\Fetch\Const_Fetch_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Include_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Codebase\Variable_Use_Graph;
use Psalm\Internal\Data_Flow\Data_Flow_Node;
use Psalm\Internal\Type\Comparator\Union_Type_Comparator;
use Psalm\Issue\Forbidden_Code;
use Psalm\Issue\Possible_Raw_Object_Iteration;
use Psalm\Issue\Raw_Object_Iteration;
use Psalm\Issue\Redundant_Function_Call;
use Psalm\Issue\Redundant_Function_Call_Given_Docblock_Type;
use Psalm\Issue_Buffer;
use Psalm\Node\Expr\Virtual_Array;
use Psalm\Node\Expr\Virtual_Variable;
use Psalm\Node\Scalar\Virtual_String;
use Psalm\Node\Virtual_Array_Item;
use Psalm\Type;
use Psalm\Type\Atomic\T_Bool;
use Psalm\Type\Atomic\T_Class_String;
use Psalm\Type\Atomic\T_Closed_Resource;
use Psalm\Type\Atomic\T_Dependent_Get_Class;
use Psalm\Type\Atomic\T_Dependent_Get_Debug_Type;
use Psalm\Type\Atomic\T_Dependent_Get_Type;
use Psalm\Type\Atomic\T_Float;
use Psalm\Type\Atomic\T_Int;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Atomic\T_Lowercase_String;
use Psalm\Type\Atomic\T_Mixed;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Null;
use Psalm\Type\Atomic\T_Object;
use Psalm\Type\Atomic\T_String;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Atomic\T_Template_Param_Class;
use Psalm\Type\Reconciler;
use Psalm\Type\Union;
use function array_map;
use function count;
use function extension_loaded;
use function in_array;
use function is_numeric;
use function is_string;
use function preg_match;
use function str_starts_with;
use function strpos;
use function strtolower;
use const EXTR_OVERWRITE;
use const EXTR_SKIP;
/**
 * @internal
 */
final class Named_Function_Call_Handler
{
    /**
     * @param lowercase-string $function_id
     */
    public static function handle(Statements_Analyzer $statements_analyzer, Codebase $codebase, Php_Parser\Node\Expr\Func_Call $stmt, Php_Parser\Node\Expr\Func_Call $real_stmt, Php_Parser\Node\Name $function_name, string $function_id, Context $context): void
    {
        if ($function_id === 'get_class' || $function_id === 'gettype' || $function_id === 'get_debug_type') {
            self::handle_dependent_type_function($statements_analyzer, $stmt, $real_stmt, $function_id, $context);
            return;
        }
        if ($stmt->is_first_class_callable()) {
            return;
        }
        $first_arg = $stmt->get_args()[0] ?? null;
        if ($function_id === 'method_exists') {
            $second_arg = $stmt->get_args()[1] ?? null;
            if ($first_arg && $first_arg->value instanceof Php_Parser\Node\Expr\Variable && $second_arg && $second_arg->value instanceof Php_Parser\Node\Scalar\String_) {
                // do nothing
            } else {
                $context->check_methods = false;
            }
            return;
        }
        if ($function_id === 'class_exists') {
            if ($first_arg) {
                if ($first_arg->value instanceof Php_Parser\Node\Scalar\String_) {
                    if (!$codebase->classlikes->class_exists($first_arg->value->value)) {
                        $context->phantom_classes[strtolower($first_arg->value->value)] = true;
                    }
                } elseif ($first_arg->value instanceof Php_Parser\Node\Expr\Class_Const_Fetch && $first_arg->value->class instanceof Php_Parser\Node\Name && $first_arg->value->name instanceof Php_Parser\Node\Identifier && $first_arg->value->name->name === 'class') {
                    $resolved_name = (string) $first_arg->value->class->get_attribute('resolvedName');
                    if (!$codebase->classlikes->class_exists($resolved_name)) {
                        $context->phantom_classes[strtolower($resolved_name)] = true;
                    }
                }
            }
            return;
        }
        if ($function_id === 'interface_exists') {
            if ($first_arg) {
                if ($first_arg->value instanceof Php_Parser\Node\Scalar\String_) {
                    if (!$codebase->classlikes->interface_exists($first_arg->value->value)) {
                        $context->phantom_classes[strtolower($first_arg->value->value)] = true;
                    }
                } elseif ($first_arg->value instanceof Php_Parser\Node\Expr\Class_Const_Fetch && $first_arg->value->class instanceof Php_Parser\Node\Name && $first_arg->value->name instanceof Php_Parser\Node\Identifier && $first_arg->value->name->name === 'class') {
                    $resolved_name = (string) $first_arg->value->class->get_attribute('resolvedName');
                    if (!$codebase->classlikes->interface_exists($resolved_name)) {
                        $context->phantom_classes[strtolower($resolved_name)] = true;
                    }
                }
            }
            return;
        }
        if ($function_id === 'enum_exists') {
            if ($first_arg) {
                if ($first_arg->value instanceof Php_Parser\Node\Scalar\String_) {
                    if (!$codebase->classlikes->enum_exists($first_arg->value->value)) {
                        $context->phantom_classes[strtolower($first_arg->value->value)] = true;
                    }
                } elseif ($first_arg->value instanceof Php_Parser\Node\Expr\Class_Const_Fetch && $first_arg->value->class instanceof Php_Parser\Node\Name && $first_arg->value->name instanceof Php_Parser\Node\Identifier && $first_arg->value->name->name === 'class') {
                    $resolved_name = (string) $first_arg->value->class->get_attribute('resolvedName');
                    if (!$codebase->classlikes->enum_exists($resolved_name)) {
                        $context->phantom_classes[strtolower($resolved_name)] = true;
                    }
                }
            }
            return;
        }
        if (in_array($function_id, ['is_file', 'file_exists']) && $first_arg) {
            $var_id = Expression_Identifier::get_extended_var_id($first_arg->value, null);
            if ($var_id) {
                $context->phantom_files[$var_id] = true;
                return;
            }
            // literal string or (magic) const in file path
            $codebase = $statements_analyzer->get_codebase();
            $config = $codebase->config;
            $path_to_file = Include_Analyzer::get_path_to($first_arg->value, $statements_analyzer->node_data, $statements_analyzer, $statements_analyzer->get_file_name(), $config);
            if ($path_to_file) {
                $context->phantom_files[$path_to_file] = true;
            }
            return;
        }
        if ($function_id === 'extension_loaded') {
            if ($first_arg && $first_arg->value instanceof Php_Parser\Node\Scalar\String_) {
                if (@extension_loaded($first_arg->value->value)) {
                    // do nothing
                } else {
                    $context->check_classes = false;
                }
            }
            return;
        }
        if ($function_id === 'function_exists') {
            $context->check_functions = false;
            return;
        }
        if ($function_id === 'is_callable') {
            $context->check_methods = false;
            $context->check_functions = false;
            return;
        }
        if ($function_id === 'defined') {
            if ($first_arg && !$context->inside_negation) {
                $fq_const_name = Const_Fetch_Analyzer::get_const_name($first_arg->value, $statements_analyzer->node_data, $codebase, $statements_analyzer->get_aliases());
                if ($fq_const_name !== null) {
                    $const_type = Const_Fetch_Analyzer::get_const_type($statements_analyzer, $fq_const_name, true, $context);
                    if (!$const_type) {
                        Const_Fetch_Analyzer::set_const_type($statements_analyzer, $fq_const_name, Type::get_mixed(), $context);
                        $context->check_consts = false;
                    }
                } else {
                    $context->check_consts = false;
                }
            } else {
                $context->check_consts = false;
            }
            return;
        }
        if ($function_id === 'extract') {
            $flag_value = false;
            if (!isset($stmt->args[1])) {
                $flag_value = EXTR_OVERWRITE;
            } elseif (isset($stmt->args[1]->value) && $stmt->args[1]->value instanceof Php_Parser\Node\Expr && ($flags_type = $statements_analyzer->node_data->get_type($stmt->args[1]->value)) && $flags_type->has_literal_int() && count($flags_type->get_atomic_types()) === 1) {
                $flag_type_value = $flags_type->get_single_int_literal()->value;
                if ($flag_type_value === EXTR_SKIP) {
                    $flag_value = EXTR_SKIP;
                } elseif ($flag_type_value === EXTR_OVERWRITE) {
                    $flag_value = EXTR_OVERWRITE;
                }
                // @todo add support for other flags
            }
            $is_unsealed = true;
            $validated_var_ids = [];
            if ($flag_value !== false && isset($stmt->args[0]->value) && $stmt->args[0]->value instanceof Php_Parser\Node\Expr && ($array_type_union = $statements_analyzer->node_data->get_type($stmt->args[0]->value)) && $array_type_union->is_single()) {
                foreach ($array_type_union->get_atomic_types() as $array_type) {
                    if ($array_type instanceof T_Keyed_Array) {
                        foreach ($array_type->properties as $key => $type) {
                            // variables must start with letters or underscore
                            if ($key === '') {
                                continue;
                            }
                            if (is_numeric($key)) {
                                continue;
                            }
                            if (preg_match('/^[A-Za-z_]/', (string) $key) !== 1) {
                                continue;
                            }
                            $var_id = '$' . $key;
                            $validated_var_ids[] = $var_id;
                            if (isset($context->vars_in_scope[$var_id]) && $flag_value === EXTR_SKIP) {
                                continue;
                            }
                            if (!isset($context->vars_in_scope[$var_id]) && $type->possibly_undefined === true) {
                                $context->possibly_assigned_var_ids[$var_id] = true;
                            } elseif (isset($context->vars_in_scope[$var_id]) && $type->possibly_undefined === true && $flag_value === EXTR_OVERWRITE) {
                                $type = Type::combine_union_types($context->vars_in_scope[$var_id], $type, $codebase, false, true, 500, false);
                            }
                            $context->vars_in_scope[$var_id] = $type;
                            $context->assigned_var_ids[$var_id] = (int) $stmt->get_attribute('startFilePos');
                        }
                        if (!isset($array_type->fallback_params)) {
                            $is_unsealed = false;
                        }
                    }
                }
            }
            if ($flag_value === EXTR_OVERWRITE && $is_unsealed === false) {
                return;
            }
            if ($flag_value === EXTR_SKIP && $is_unsealed === false) {
                return;
            }
            $context->check_variables = false;
            if ($flag_value === EXTR_SKIP) {
                return;
            }
            foreach ($context->vars_in_scope as $var_id => $_) {
                if ($var_id === '$this') {
                    continue;
                }
                if (strpos($var_id, '[')) {
                    continue;
                }
                if (strpos($var_id, '>')) {
                    continue;
                }
                if (in_array($var_id, $validated_var_ids, true)) {
                    continue;
                }
                $mixed_type = new Union([new T_Mixed()], ['parent_nodes' => $context->vars_in_scope[$var_id]->parent_nodes]);
                $context->vars_in_scope[$var_id] = $mixed_type;
                $context->assigned_var_ids[$var_id] = (int) $stmt->get_attribute('startFilePos');
                $context->possibly_assigned_var_ids[$var_id] = true;
            }
            return;
        }
        if ($function_id === 'compact') {
            $all_args_string_literals = true;
            $new_items = [];
            foreach ($stmt->get_args() as $arg) {
                $arg_type = $statements_analyzer->node_data->get_type($arg->value);
                if (!$arg_type || !$arg_type->is_single_string_literal()) {
                    $all_args_string_literals = false;
                    break;
                }
                $var_name = $arg_type->get_single_string_literal()->value;
                $new_items[] = new Virtual_Array_Item(new Virtual_Variable($var_name, $arg->value->get_attributes()), new Virtual_String($var_name, $arg->value->get_attributes()), false, $arg->get_attributes());
            }
            if ($all_args_string_literals) {
                $arr = new Virtual_Array($new_items, $stmt->get_attributes());
                $old_node_data = $statements_analyzer->node_data;
                $statements_analyzer->node_data = clone $statements_analyzer->node_data;
                Expression_Analyzer::analyze($statements_analyzer, $arr, $context);
                $arr_type = $statements_analyzer->node_data->get_type($arr);
                $statements_analyzer->node_data = $old_node_data;
                if ($arr_type) {
                    $statements_analyzer->node_data->set_type($stmt, $arr_type);
                }
            }
            return;
        }
        if ($function_id === 'func_get_args') {
            $source = $statements_analyzer->get_source();
            if ($source instanceof Function_Like_Analyzer) {
                if ($statements_analyzer->data_flow_graph instanceof Variable_Use_Graph) {
                    foreach ($source->param_nodes as $param_node) {
                        $statements_analyzer->data_flow_graph->add_path($param_node, new Data_Flow_Node('variable-use', 'variable use', null), 'variable-use');
                    }
                }
            }
            return;
        }
        if ($function_id === 'var_dump' || $function_id === 'shell_exec') {
            Issue_Buffer::maybe_add(new Forbidden_Code('Unsafe ' . $function_id, new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
        }
        if (isset($codebase->config->forbidden_functions[$function_id])) {
            Issue_Buffer::maybe_add(new Forbidden_Code('You have forbidden the use of ' . $function_id, new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
            return;
        }
        if ($function_id === 'define') {
            if ($first_arg) {
                $fq_const_name = Const_Fetch_Analyzer::get_const_name($first_arg->value, $statements_analyzer->node_data, $codebase, $statements_analyzer->get_aliases());
                if ($fq_const_name !== null && isset($stmt->get_args()[1])) {
                    $second_arg = $stmt->get_args()[1];
                    $was_in_call = $context->inside_call;
                    $context->inside_call = true;
                    Expression_Analyzer::analyze($statements_analyzer, $second_arg->value, $context);
                    $context->inside_call = $was_in_call;
                    Const_Fetch_Analyzer::set_const_type($statements_analyzer, $fq_const_name, $statements_analyzer->node_data->get_type($second_arg->value) ?? Type::get_mixed(), $context);
                }
            } else {
                $context->check_consts = false;
            }
            return;
        }
        if ($function_id === 'constant') {
            if ($first_arg) {
                $fq_const_name = Const_Fetch_Analyzer::get_const_name($first_arg->value, $statements_analyzer->node_data, $codebase, $statements_analyzer->get_aliases());
                if ($fq_const_name !== null) {
                    $const_type = Const_Fetch_Analyzer::get_const_type($statements_analyzer, $fq_const_name, true, $context);
                    if ($const_type) {
                        $statements_analyzer->node_data->set_type($real_stmt, $const_type);
                    }
                }
            } else {
                $context->check_consts = false;
            }
        }
        if ($first_arg && $function_id && str_starts_with($function_id, 'is_') && $function_id !== 'is_a' && !$context->inside_negation) {
            $stmt_assertions = $statements_analyzer->node_data->get_assertions($stmt);
            $anded_assertions = $stmt_assertions ?? Assertion_Finder::process_function_call($stmt, $context->self, $statements_analyzer, $codebase, $context->inside_negation);
            $changed_vars = [];
            foreach ($anded_assertions as $assertions) {
                $referenced_var_ids = array_map(static fn(array $_): bool => true, $assertions);
                Reconciler::reconcile_keyed_types($assertions, $assertions, $context->vars_in_scope, $context->references_in_scope, $changed_vars, $referenced_var_ids, $statements_analyzer, [], $context->inside_loop, new Code_Location($statements_analyzer->get_source(), $stmt));
            }
            return;
        }
        if ($first_arg && ($function_id === 'array_values' || $function_id === 'ksort')) {
            $first_arg_type = $statements_analyzer->node_data->get_type($first_arg->value);
            if ($first_arg_type && Union_Type_Comparator::is_contained_by($codebase, $first_arg_type, Type::get_list())) {
                if ($first_arg_type->from_docblock) {
                    Issue_Buffer::maybe_add(new Redundant_Function_Call_Given_Docblock_Type("The call to {$function_id} is unnecessary given the list docblock type {$first_arg_type}", new Code_Location($statements_analyzer, $function_name)), $statements_analyzer->get_suppressed_issues());
                } else {
                    Issue_Buffer::maybe_add(new Redundant_Function_Call("The call to {$function_id} is unnecessary, {$first_arg_type} is already a list", new Code_Location($statements_analyzer, $function_name)), $statements_analyzer->get_suppressed_issues());
                }
            }
        }
        if ($first_arg && $function_id === 'strtolower') {
            $first_arg_type = $statements_analyzer->node_data->get_type($first_arg->value);
            if ($first_arg_type && Union_Type_Comparator::is_contained_by($codebase, $first_arg_type, new Union([new T_Lowercase_String()]))) {
                if ($first_arg_type->from_docblock) {
                    Issue_Buffer::maybe_add(new Redundant_Function_Call_Given_Docblock_Type('The call to strtolower is unnecessary given the docblock type', new Code_Location($statements_analyzer, $function_name)), $statements_analyzer->get_suppressed_issues());
                } else {
                    Issue_Buffer::maybe_add(new Redundant_Function_Call('The call to strtolower is unnecessary', new Code_Location($statements_analyzer, $function_name)), $statements_analyzer->get_suppressed_issues());
                }
            }
        }
        if ($first_arg && ($function_id === 'array_walk' || $function_id === 'array_walk_recursive')) {
            $first_arg_type = $statements_analyzer->node_data->get_type($first_arg->value);
            if ($first_arg_type && $first_arg_type->has_object_type()) {
                if ($first_arg_type->is_single()) {
                    Issue_Buffer::maybe_add(new Raw_Object_Iteration('Possibly undesired iteration over object properties', new Code_Location($statements_analyzer, $function_name)));
                } else {
                    Issue_Buffer::maybe_add(new Possible_Raw_Object_Iteration('Possibly undesired iteration over object properties', new Code_Location($statements_analyzer, $function_name)));
                }
            }
        }
        if ($first_arg && $function_id === 'is_a' && !$context->inside_conditional) {
            $first_arg_type = $statements_analyzer->node_data->get_type($first_arg->value);
            if ($first_arg_type && $first_arg_type->is_string()) {
                $third_arg = $stmt->get_args()[2] ?? null;
                if ($third_arg) {
                    $third_arg_type = $statements_analyzer->node_data->get_type($third_arg->value);
                } else {
                    $third_arg_type = Type::get_false();
                }
                if ($third_arg_type && $third_arg_type->is_single() && $third_arg_type->is_false()) {
                    if ($first_arg_type->from_docblock) {
                        Issue_Buffer::maybe_add(new Redundant_Function_Call_Given_Docblock_Type('Call to is_a always return false when first argument is string ' . 'unless third argument is true', new Code_Location($statements_analyzer, $function_name)));
                    } else {
                        Issue_Buffer::maybe_add(new Redundant_Function_Call('Call to is_a always return false when first argument is string ' . 'unless third argument is true', new Code_Location($statements_analyzer, $function_name)));
                    }
                }
            }
        }
    }
    private static function handle_dependent_type_function(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Func_Call $stmt, Php_Parser\Node\Expr\Func_Call $real_stmt, string $function_id, Context $context): void
    {
        $first_arg = $stmt->get_args()[0] ?? null;
        if ($first_arg) {
            $var = $first_arg->value;
            if ($var instanceof Php_Parser\Node\Expr\Variable && is_string($var->name)) {
                $var_id = '$' . $var->name;
                if (isset($context->vars_in_scope[$var_id])) {
                    if (!$context->vars_in_scope[$var_id]->has_template()) {
                        if ($function_id === 'get_class') {
                            $atomic_type = new T_Dependent_Get_Class($var_id, $context->vars_in_scope[$var_id]->has_mixed() ? Type::get_object() : $context->vars_in_scope[$var_id]);
                        } elseif ($function_id === 'gettype') {
                            $atomic_type = new T_Dependent_Get_Type($var_id);
                        } else {
                            $atomic_type = new T_Dependent_Get_Debug_Type($var_id);
                        }
                        $statements_analyzer->node_data->set_type($real_stmt, new Union([$atomic_type]));
                        return;
                    }
                }
            }
            if (($var_type = $statements_analyzer->node_data->get_type($var)) && ($function_id === 'get_class' || $function_id === 'get_debug_type')) {
                $class_string_types = [];
                foreach ($var_type->get_atomic_types() as $class_type) {
                    if ($class_type instanceof T_Named_Object) {
                        $class_string_types[] = new T_Class_String($class_type->value, $class_type);
                    } elseif ($class_type instanceof T_Template_Param && $class_type->as->is_single()) {
                        $as_atomic_type = $class_type->as->get_single_atomic();
                        if ($as_atomic_type instanceof T_Object) {
                            $class_string_types[] = new T_Template_Param_Class($class_type->param_name, 'object', null, $class_type->defining_class);
                        } elseif ($as_atomic_type instanceof T_Named_Object) {
                            $class_string_types[] = new T_Template_Param_Class($class_type->param_name, $as_atomic_type->value, $as_atomic_type, $class_type->defining_class);
                        }
                    } elseif ($function_id === 'get_class') {
                        $class_string_types[] = new T_Class_String();
                    } else if ($class_type instanceof T_Int) {
                        $class_string_types[] = Type::get_atomic_string_from_literal('int');
                    } elseif ($class_type instanceof T_String) {
                        $class_string_types[] = Type::get_atomic_string_from_literal('string');
                    } elseif ($class_type instanceof T_Float) {
                        $class_string_types[] = Type::get_atomic_string_from_literal('float');
                    } elseif ($class_type instanceof T_Bool) {
                        $class_string_types[] = Type::get_atomic_string_from_literal('bool');
                    } elseif ($class_type instanceof T_Closed_Resource) {
                        $class_string_types[] = Type::get_atomic_string_from_literal('resource (closed)');
                    } elseif ($class_type instanceof T_Null) {
                        $class_string_types[] = Type::get_atomic_string_from_literal('null');
                    } else {
                        $class_string_types[] = new T_String();
                    }
                }
                if ($class_string_types) {
                    $statements_analyzer->node_data->set_type($real_stmt, new Union($class_string_types));
                }
            }
        } elseif ($function_id === 'get_class' && $get_class_name = $statements_analyzer->get_fqcln()) {
            $statements_analyzer->node_data->set_type($real_stmt, new Union([new T_Class_String($get_class_name, new T_Named_Object($get_class_name))]));
        }
    }
}