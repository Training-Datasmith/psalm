<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression\Call;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Internal\Algebra;
use Psalm\Internal\Algebra\Formula_Generator;
use Psalm\Internal\Analyzer\Algebra_Analyzer;
use Psalm\Internal\Analyzer\Function_Like_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Analyzer\Trait_Analyzer;
use Psalm\Internal\Codebase\Internal_Call_Map_Handler;
use Psalm\Internal\Codebase\Taint_Flow_Graph;
use Psalm\Internal\Data_Flow\Taint_Sink;
use Psalm\Internal\Data_Flow\Taint_Source;
use Psalm\Internal\Method_Identifier;
use Psalm\Internal\Type\Comparator\Callable_Type_Comparator;
use Psalm\Internal\Type\Template_Result;
use Psalm\Internal\Type\Type_Combiner;
use Psalm\Issue\Deprecated_Function;
use Psalm\Issue\Impure_Function_Call;
use Psalm\Issue\Invalid_Function_Call;
use Psalm\Issue\Mixed_Function_Call;
use Psalm\Issue\Null_Function_Call;
use Psalm\Issue\Possibly_Invalid_Function_Call;
use Psalm\Issue\Possibly_Null_Function_Call;
use Psalm\Issue\Unused_Function_Call;
use Psalm\Issue_Buffer;
use Psalm\Node\Expr\Virtual_Func_Call;
use Psalm\Node\Expr\Virtual_Method_Call;
use Psalm\Node\Name\Virtual_Fully_Qualified;
use Psalm\Node\Virtual_Arg;
use Psalm\Node\Virtual_Identifier;
use Psalm\Plugin\Event_Handler\Event\Add_Remove_Taints_Event;
use Psalm\Plugin\Event_Handler\Event\After_Every_Function_Call_Analysis_Event;
use Psalm\Storage\Function_Like_Parameter;
use Psalm\Storage\Function_Storage;
use Psalm\Storage\Possibilities;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Callable;
use Psalm\Type\Atomic\T_Callable_Object;
use Psalm\Type\Atomic\T_Callable_String;
use Psalm\Type\Atomic\T_Closure;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Atomic\T_Literal_String;
use Psalm\Type\Atomic\T_Mixed;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Null;
use Psalm\Type\Atomic\T_Object_With_Properties;
use Psalm\Type\Atomic\T_String;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Reconciler;
use Psalm\Type\Taint_Kind;
use Psalm\Type\Union;
use UnexpectedValueException;
use function array_diff;
use function array_map;
use function array_merge;
use function array_shift;
use function array_slice;
use function count;
use function explode;
use function implode;
use function in_array;
use function preg_replace;
use function reset;
use function spl_object_id;
use function strpos;
use function strtolower;
/**
 * @internal
 */
final class Function_Call_Analyzer extends Call_Analyzer
{
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Func_Call $stmt, Context $context, ?Template_Result $template_result = null): bool
    {
        $function_name = $stmt->name;
        $codebase = $statements_analyzer->get_codebase();
        $code_location = new Code_Location($statements_analyzer->get_source(), $stmt);
        $config = $codebase->config;
        $is_first_class_callable = $stmt->is_first_class_callable();
        $real_stmt = $stmt;
        if ($function_name instanceof Php_Parser\Node\Name && !$is_first_class_callable && isset($stmt->get_args()[0]) && !$stmt->get_args()[0]->unpack) {
            $original_function_id = implode('\\', $function_name->get_parts());
            if ($original_function_id === 'call_user_func') {
                $other_args = array_slice($stmt->get_args(), 1);
                $function_name = $stmt->get_args()[0]->value;
                $stmt = new Virtual_Func_Call($function_name, $other_args, $stmt->get_attributes());
            }
            if ($original_function_id === 'call_user_func_array' && isset($stmt->get_args()[1])) {
                $function_name = $stmt->get_args()[0]->value;
                $stmt = new Virtual_Func_Call($function_name, [new Virtual_Arg($stmt->get_args()[1]->value, false, true)], $stmt->get_attributes());
            }
        }
        if ($function_name instanceof Php_Parser\Node\Expr) {
            $function_call_info = self::get_analyze_named_expression($statements_analyzer, $stmt, $real_stmt, $function_name, $context);
            if ($function_call_info->function_exists === false) {
                return true;
            }
            if ($function_call_info->new_function_name) {
                $function_name = $function_call_info->new_function_name;
            }
        } else {
            $function_call_info = self::handle_named_function($statements_analyzer, $stmt, $function_name, $context, $code_location);
            if (!$function_call_info->function_exists) {
                return true;
            }
        }
        $set_inside_conditional = false;
        if ($function_name instanceof Php_Parser\Node\Name && $function_name->get_parts() === ['assert'] && !$context->inside_conditional) {
            $context->inside_conditional = true;
            $set_inside_conditional = true;
        }
        if (!$template_result) {
            $template_result = new Template_Result([], []);
        }
        if (!$is_first_class_callable) {
            if (isset($function_call_info->function_storage->template_types)) {
                $template_result->template_types += $function_call_info->function_storage->template_types ?: [];
            }
            Arguments_Analyzer::analyze($statements_analyzer, $stmt->get_args(), $function_call_info->function_params, $function_call_info->function_id, $function_call_info->allow_named_args, $context, $template_result);
        }
        if ($set_inside_conditional) {
            $context->inside_conditional = false;
        }
        $function_callable = null;
        if (!$is_first_class_callable && $function_name instanceof Php_Parser\Node\Name && $function_call_info->function_id) {
            if (!$function_call_info->is_stubbed && $function_call_info->in_call_map) {
                $function_callable = Internal_Call_Map_Handler::get_callable_from_call_map_by_id($codebase, $function_call_info->function_id, $stmt->get_args(), $statements_analyzer->node_data);
                if (!$codebase->functions->params_provider->has($function_call_info->function_id)) {
                    $function_call_info->function_params = $function_callable->params;
                }
            }
        }
        $already_inferred_lower_bounds = $template_result->lower_bounds;
        $template_result = new Template_Result([], []);
        // do this here to allow closure param checks
        if (!$is_first_class_callable && $function_call_info->function_params !== null) {
            Arguments_Analyzer::check_arguments_match($statements_analyzer, $stmt->get_args(), $function_call_info->function_id, $function_call_info->function_params, $function_call_info->function_storage, null, $template_result, $code_location, $context);
        }
        Call_Analyzer::check_template_result($statements_analyzer, $template_result, $code_location, $function_call_info->function_id);
        $template_result->lower_bounds = [...$template_result->lower_bounds, ...$already_inferred_lower_bounds];
        if ($function_name instanceof Php_Parser\Node\Name && $function_call_info->function_id) {
            $stmt_type = Function_Call_Return_Type_Fetcher::fetch($statements_analyzer, $codebase, $stmt, $function_name, $function_call_info->function_id, $function_call_info->in_call_map, $function_call_info->is_stubbed, $function_call_info->function_storage, $function_callable, $template_result, $context);
            $statements_analyzer->node_data->set_type($real_stmt, $stmt_type);
            if ($stmt_type->is_never()) {
                $context->has_returned = true;
            }
            $event = new After_Every_Function_Call_Analysis_Event($stmt, $function_call_info->function_id, $context, $statements_analyzer->get_source(), $codebase);
            $config->event_dispatcher->dispatch_after_every_function_call_analysis($event);
            if ($is_first_class_callable) {
                return true;
            }
        }
        if ($is_first_class_callable) {
            $type_provider = $statements_analyzer->get_node_type_provider();
            $closure_types = [];
            if ($input_type = $type_provider->get_type($function_name)) {
                foreach ($input_type->get_atomic_types() as $atomic_type) {
                    $candidate_callable = Callable_Type_Comparator::get_callable_from_atomic($codebase, $atomic_type, null, $statements_analyzer);
                    if ($candidate_callable) {
                        $closure_types[] = new T_Closure('Closure', $candidate_callable->params, $candidate_callable->return_type, $candidate_callable->is_pure);
                    }
                }
            }
            if ($closure_types) {
                $stmt_type = Type_Combiner::combine($closure_types, $codebase);
            } else {
                $stmt_type = Type::get_closure();
            }
            $statements_analyzer->node_data->set_type($real_stmt, $stmt_type);
            return true;
        }
        foreach ($function_call_info->defined_constants as $const_name => $const_type) {
            $context->constants[$const_name] = $const_type;
            $context->vars_in_scope[$const_name] = $const_type;
        }
        foreach ($function_call_info->global_variables as $var_id => $_) {
            $context->vars_in_scope[$var_id] = Type::get_mixed();
            $context->vars_possibly_in_scope[$var_id] = true;
        }
        if ($function_name instanceof Php_Parser\Node\Name && $function_name->get_parts() === ['assert'] && isset($stmt->get_args()[0])) {
            self::process_assert_function_effects($statements_analyzer, $codebase, $stmt, $stmt->get_args()[0], $context);
        }
        if ($codebase->store_node_types && !$context->collect_initializations && !$context->collect_mutations && $stmt_type = $statements_analyzer->node_data->get_type($real_stmt)) {
            $codebase->analyzer->add_node_type($statements_analyzer->get_file_path(), $stmt, $stmt_type->get_id());
        }
        self::check_function_call_purity($statements_analyzer, $codebase, $stmt, $function_name, $function_call_info, $context);
        if ($function_call_info->function_storage) {
            if ($function_call_info->function_storage->assertions && $function_name instanceof Php_Parser\Node\Name) {
                self::apply_assertions_to_context($function_name, null, $function_call_info->function_storage->assertions, $stmt->get_args(), $template_result, $context, $statements_analyzer);
            }
            if ($function_call_info->function_storage->if_true_assertions) {
                $statements_analyzer->node_data->set_if_true_assertions($stmt, array_map(static fn(Possibilities $assertion): Possibilities => $assertion->get_untemplated_copy($template_result, null, $codebase), $function_call_info->function_storage->if_true_assertions));
            }
            if ($function_call_info->function_storage->if_false_assertions) {
                $statements_analyzer->node_data->set_if_false_assertions($stmt, array_map(static fn(Possibilities $assertion): Possibilities => $assertion->get_untemplated_copy($template_result, null, $codebase), $function_call_info->function_storage->if_false_assertions));
            }
            if ($function_call_info->function_storage->deprecated && $function_call_info->function_id) {
                Issue_Buffer::maybe_add(new Deprecated_Function('The function ' . $function_call_info->function_id . ' has been marked as deprecated', $code_location, $function_call_info->function_id), $statements_analyzer->get_suppressed_issues());
            }
        }
        if ($function_name instanceof Php_Parser\Node\Name && $function_call_info->function_id) {
            Named_Function_Call_Handler::handle($statements_analyzer, $codebase, $stmt, $real_stmt, $function_name, strtolower($function_call_info->function_id), $context);
        }
        if (!$statements_analyzer->node_data->get_type($real_stmt)) {
            $statements_analyzer->node_data->set_type($real_stmt, Type::get_mixed());
        }
        return true;
    }
    private static function handle_named_function(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Func_Call $stmt, Php_Parser\Node\Name $function_name, Context $context, Code_Location $code_location): Function_Call_Info
    {
        $function_call_info = new Function_Call_Info();
        $codebase = $statements_analyzer->get_codebase();
        $codebase_functions = $codebase->functions;
        $original_function_id = $function_name->to_string();
        if (!$function_name instanceof Php_Parser\Node\Name\Fully_Qualified) {
            $function_call_info->function_id = $codebase_functions->get_fully_qualified_function_name_from_string($original_function_id, $statements_analyzer);
        } else {
            $function_call_info->function_id = $original_function_id;
        }
        $namespaced_function_exists = $codebase_functions->function_exists($statements_analyzer, strtolower($function_call_info->function_id));
        if (!$namespaced_function_exists && !$function_name instanceof Php_Parser\Node\Name\Fully_Qualified) {
            $function_call_info->in_call_map = Internal_Call_Map_Handler::in_call_map($original_function_id);
            $function_call_info->is_stubbed = $codebase_functions->has_stubbed_function($original_function_id);
            if ($function_call_info->is_stubbed || $function_call_info->in_call_map) {
                $function_call_info->function_id = $original_function_id;
            }
        } else {
            $function_call_info->in_call_map = Internal_Call_Map_Handler::in_call_map($function_call_info->function_id);
            $function_call_info->is_stubbed = $codebase_functions->has_stubbed_function($function_call_info->function_id);
        }
        $function_call_info->function_exists = $function_call_info->is_stubbed || $function_call_info->in_call_map || $namespaced_function_exists;
        if ($function_call_info->function_exists && !$stmt->is_first_class_callable() && $codebase->store_node_types && !$context->collect_initializations && !$context->collect_mutations) {
            Argument_Map_Populator::record_argument_positions($statements_analyzer, $stmt, $codebase, $function_call_info->function_id);
        }
        $is_predefined = true;
        $is_maybe_root_function = !$function_name instanceof Php_Parser\Node\Name\Fully_Qualified && count($function_name->get_parts()) === 1;
        $args = $stmt->is_first_class_callable() ? [] : $stmt->get_args();
        if (!$function_call_info->in_call_map) {
            $predefined_functions = $codebase->config->get_predefined_functions();
            $is_predefined = isset($predefined_functions[strtolower($original_function_id)]) || isset($predefined_functions[strtolower($function_call_info->function_id)]);
            if ($context->check_functions) {
                if (self::check_function_exists($statements_analyzer, $function_call_info->function_id, $code_location, $is_maybe_root_function) === false) {
                    if ($args) {
                        Arguments_Analyzer::analyze($statements_analyzer, $args, null, null, true, $context);
                    }
                    return $function_call_info;
                }
                $function_call_info->function_exists = true;
            }
        } else {
            $function_call_info->function_exists = true;
        }
        $function_call_info->function_params = null;
        $function_call_info->defined_constants = [];
        $function_call_info->global_variables = [];
        $args = $stmt->is_first_class_callable() ? [] : $stmt->get_args();
        $dynamic_function_storage = null;
        if ($codebase->functions->dynamic_storage_provider->has($function_call_info->function_id)) {
            $dynamic_function_storage = $codebase->functions->dynamic_storage_provider->get_function_storage($stmt, $statements_analyzer, $function_call_info->function_id, $context, $code_location);
        }
        if ($function_call_info->function_exists) {
            if ($dynamic_function_storage) {
                $function_call_info->function_storage = $dynamic_function_storage;
                $function_call_info->function_params = $dynamic_function_storage->params;
                $function_call_info->allow_named_args = $dynamic_function_storage->allow_named_arg_calls;
                $function_call_info->defined_constants = $dynamic_function_storage->defined_constants;
                $function_call_info->global_variables = $dynamic_function_storage->global_variables;
            } elseif (!$function_call_info->in_call_map || $function_call_info->is_stubbed) {
                try {
                    $function_call_info->function_storage = $function_storage = $codebase_functions->get_storage($statements_analyzer, strtolower($function_call_info->function_id));
                    $function_call_info->function_params = $function_call_info->function_storage->params;
                    if (!$function_storage->allow_named_arg_calls) {
                        $function_call_info->allow_named_args = false;
                    }
                    if (!$is_predefined) {
                        $function_call_info->defined_constants = $function_storage->defined_constants;
                        $function_call_info->global_variables = $function_storage->global_variables;
                    }
                } catch (UnexpectedValueException) {
                    $function_call_info->function_params = [new Function_Like_Parameter('args', false, null, null, null, null, false, false, true)];
                }
            } else {
                $function_callable = Internal_Call_Map_Handler::get_callable_from_call_map_by_id($codebase, $function_call_info->function_id, $args, $statements_analyzer->node_data);
                $function_call_info->function_params = $function_callable->params;
            }
            if ($codebase->functions->params_provider->has($function_call_info->function_id)) {
                $function_call_info->function_params = $codebase->functions->params_provider->get_function_params($statements_analyzer, $function_call_info->function_id, $args, $context, $code_location);
            }
            if ($codebase->store_node_types && !$context->collect_initializations && !$context->collect_mutations) {
                $codebase->analyzer->add_node_reference($statements_analyzer->get_file_path(), $function_name, $function_call_info->function_id . '()');
            }
        }
        return $function_call_info;
    }
    private static function get_analyze_named_expression(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Func_Call $stmt, Php_Parser\Node\Expr\Func_Call $real_stmt, Php_Parser\Node\Expr $function_name, Context $context): Function_Call_Info
    {
        $function_call_info = new Function_Call_Info();
        $codebase = $statements_analyzer->get_codebase();
        $was_in_call = $context->inside_call;
        $context->inside_call = true;
        if (Expression_Analyzer::analyze($statements_analyzer, $function_name, $context) === false) {
            $context->inside_call = $was_in_call;
            return $function_call_info;
        }
        $context->inside_call = $was_in_call;
        $function_call_info->byref_uses = [];
        if ($stmt_name_type = $statements_analyzer->node_data->get_type($function_name)) {
            if ($stmt_name_type->is_null()) {
                Issue_Buffer::maybe_add(new Null_Function_Call('Cannot call function on null value', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
                return $function_call_info;
            }
            if ($stmt_name_type->is_nullable()) {
                Issue_Buffer::maybe_add(new Possibly_Null_Function_Call('Cannot call function on possibly null value', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
            }
            $invalid_function_call_types = [];
            $has_valid_function_call_type = false;
            $var_atomic_types = $stmt_name_type->get_atomic_types();
            while ($var_atomic_types) {
                $var_type_part = array_shift($var_atomic_types);
                if ($var_type_part instanceof T_Template_Param) {
                    $var_atomic_types = array_merge($var_atomic_types, $var_type_part->as->get_atomic_types());
                    continue;
                }
                if ($var_type_part instanceof T_Closure || $var_type_part instanceof T_Callable) {
                    if (!$var_type_part->is_pure) {
                        if ($context->pure || $context->mutation_free) {
                            Issue_Buffer::maybe_add(new Impure_Function_Call('Cannot call an impure function from a mutation-free context', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
                        }
                        if (!$function_call_info->function_storage) {
                            $function_call_info->function_storage = new Function_Storage();
                        }
                        $function_call_info->function_storage->pure = false;
                        $function_call_info->function_storage->mutation_free = false;
                    }
                    $function_call_info->function_params = $var_type_part->params;
                    if (($stmt_type = $statements_analyzer->node_data->get_type($real_stmt)) && $var_type_part->return_type) {
                        $statements_analyzer->node_data->set_type($real_stmt, Type::combine_union_types($stmt_type, $var_type_part->return_type));
                    } else {
                        $statements_analyzer->node_data->set_type($real_stmt, $var_type_part->return_type ?? Type::get_mixed());
                    }
                    if ($var_type_part instanceof T_Closure) {
                        $function_call_info->byref_uses += $var_type_part->byref_uses;
                    }
                    $function_call_info->function_exists = true;
                    $has_valid_function_call_type = true;
                } elseif ($var_type_part instanceof T_Mixed) {
                    $has_valid_function_call_type = true;
                    Issue_Buffer::maybe_add(new Mixed_Function_Call('Cannot call function on ' . $var_type_part->get_id(), new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
                } elseif ($var_type_part instanceof T_Callable_Object) {
                    $has_valid_function_call_type = true;
                    self::analyze_invoke_call($statements_analyzer, $stmt, $real_stmt, $function_name, $context, $var_type_part);
                } elseif ($var_type_part instanceof T_Callable_String || $var_type_part instanceof T_Named_Object && $var_type_part->value === 'Closure' || $var_type_part instanceof T_Object_With_Properties && isset($var_type_part->methods['__invoke'])) {
                    // this is fine
                    $has_valid_function_call_type = true;
                } elseif ($var_type_part instanceof T_String || $var_type_part instanceof T_Array || $var_type_part instanceof T_Keyed_Array && count($var_type_part->properties) === 2) {
                    $potential_method_id = null;
                    if ($var_type_part instanceof T_Keyed_Array) {
                        $potential_method_id = Callable_Type_Comparator::get_callable_method_id_from_t_keyed_array($var_type_part, $codebase, $context->calling_method_id, $statements_analyzer->get_file_path());
                        if ($potential_method_id === 'not-callable') {
                            $potential_method_id = null;
                        }
                    } elseif ($var_type_part instanceof T_Literal_String) {
                        if (!$var_type_part->value) {
                            $invalid_function_call_types[] = '\'\'';
                            continue;
                        }
                        if (strpos($var_type_part->value, '::')) {
                            $parts = explode('::', strtolower($var_type_part->value));
                            $fq_class_name = $parts[0];
                            $fq_class_name = (string) preg_replace('/^\\\\/', '', $fq_class_name, 1);
                            $potential_method_id = new Method_Identifier($fq_class_name, $parts[1]);
                        } else {
                            $function_call_info->new_function_name = new Virtual_Fully_Qualified($var_type_part->value, $function_name->get_attributes());
                        }
                    }
                    if ($potential_method_id) {
                        $codebase->methods->method_exists($potential_method_id, $context->calling_method_id, null, $statements_analyzer, $statements_analyzer->get_file_path());
                    }
                    // this is also kind of fine
                    $has_valid_function_call_type = true;
                } elseif ($var_type_part instanceof T_Null) {
                    // handled above
                } elseif (!$var_type_part instanceof T_Named_Object || !$codebase->classlikes->class_or_interface_exists($var_type_part->value) || !$codebase->methods->method_exists(new Method_Identifier($var_type_part->value, '__invoke'))) {
                    $invalid_function_call_types[] = (string) $var_type_part;
                } else {
                    self::analyze_invoke_call($statements_analyzer, $stmt, $real_stmt, $function_name, $context, $var_type_part);
                }
            }
            if ($invalid_function_call_types) {
                $var_type_part = reset($invalid_function_call_types);
                if ($has_valid_function_call_type) {
                    Issue_Buffer::maybe_add(new Possibly_Invalid_Function_Call('Cannot treat type ' . $var_type_part . ' as callable', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
                } else {
                    Issue_Buffer::maybe_add(new Invalid_Function_Call('Cannot treat type ' . $var_type_part . ' as callable', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
                }
                return $function_call_info;
            }
            if ($statements_analyzer->data_flow_graph instanceof Taint_Flow_Graph && $stmt_name_type->parent_nodes && !in_array('TaintedInput', $statements_analyzer->get_suppressed_issues())) {
                $arg_location = new Code_Location($statements_analyzer->get_source(), $function_name);
                $custom_call_sink = Taint_Sink::get_for_method_argument('variable-call', 'variable-call', 0, $arg_location, $arg_location);
                $custom_call_sink->taints = [Taint_Kind::INPUT_CALLABLE];
                $statements_analyzer->data_flow_graph->add_sink($custom_call_sink);
                $event = new Add_Remove_Taints_Event($stmt, $context, $statements_analyzer, $codebase);
                $added_taints = $codebase->config->event_dispatcher->dispatch_add_taints($event);
                $removed_taints = $codebase->config->event_dispatcher->dispatch_remove_taints($event);
                $taints = array_diff($added_taints, $removed_taints);
                if ($taints !== []) {
                    $taint_source = Taint_Source::from_node($custom_call_sink);
                    $taint_source->taints = $taints;
                    $statements_analyzer->data_flow_graph->add_source($taint_source);
                }
                foreach ($stmt_name_type->parent_nodes as $parent_node) {
                    $statements_analyzer->data_flow_graph->add_path($parent_node, $custom_call_sink, 'call', $added_taints, $removed_taints);
                }
            }
        }
        if (!$statements_analyzer->node_data->get_type($real_stmt)) {
            $statements_analyzer->node_data->set_type($real_stmt, Type::get_mixed());
        }
        return $function_call_info;
    }
    private static function analyze_invoke_call(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Func_Call $stmt, Php_Parser\Node\Expr\Func_Call $real_stmt, Php_Parser\Node\Expr $function_name, Context $context, Atomic $atomic_type): void
    {
        $old_data_provider = $statements_analyzer->node_data;
        $statements_analyzer->node_data = clone $statements_analyzer->node_data;
        $fake_method_call = new Virtual_Method_Call($function_name, new Virtual_Identifier('__invoke', $function_name->get_attributes()), $stmt->args);
        $suppressed_issues = $statements_analyzer->get_suppressed_issues();
        if (!in_array('InternalMethod', $suppressed_issues, true)) {
            $statements_analyzer->add_suppressed_issues(['InternalMethod']);
        }
        $statements_analyzer->node_data->set_type($function_name, new Union([$atomic_type]));
        Method_Call_Analyzer::analyze($statements_analyzer, $fake_method_call, $context, false);
        if (!in_array('InternalMethod', $suppressed_issues, true)) {
            $statements_analyzer->remove_suppressed_issues(['InternalMethod']);
        }
        $fake_method_call_type = $statements_analyzer->node_data->get_type($fake_method_call);
        $statements_analyzer->node_data = $old_data_provider;
        if ($stmt_type = $statements_analyzer->node_data->get_type($real_stmt)) {
            $statements_analyzer->node_data->set_type($real_stmt, Type::combine_union_types($fake_method_call_type ?? Type::get_mixed(), $stmt_type));
        } else {
            $statements_analyzer->node_data->set_type($real_stmt, $fake_method_call_type ?? Type::get_mixed());
        }
    }
    private static function process_assert_function_effects(Statements_Analyzer $statements_analyzer, Codebase $codebase, Php_Parser\Node\Expr\Func_Call $stmt, Php_Parser\Node\Arg $first_arg, Context $context): void
    {
        $first_arg_value_id = spl_object_id($first_arg->value);
        $assert_clauses = Formula_Generator::get_formula($first_arg_value_id, $first_arg_value_id, $first_arg->value, $context->self, $statements_analyzer, $codebase);
        Algebra_Analyzer::check_for_paradox($context->clauses, $assert_clauses, $statements_analyzer, $stmt, []);
        $simplified_clauses = Algebra::simplify_cnf([...$context->clauses, ...$assert_clauses]);
        $assert_type_assertions = Algebra::get_truths_from_formula($simplified_clauses);
        $changed_var_ids = [];
        if ($assert_type_assertions) {
            // while in an and, we allow scope to boil over to support
            // statements of the form if ($x && $x->foo())
            [$op_vars_in_scope, $op_references_in_scope] = Reconciler::reconcile_keyed_types($assert_type_assertions, $assert_type_assertions, $context->vars_in_scope, $context->references_in_scope, $changed_var_ids, array_map(static fn($_): bool => true, $assert_type_assertions), $statements_analyzer, $statements_analyzer->get_template_type_map() ?: [], $context->inside_loop, new Code_Location($statements_analyzer->get_source(), $stmt));
            foreach ($changed_var_ids as $var_id => $_) {
                $first_appearance = $statements_analyzer->get_first_appearance($var_id);
                if ($first_appearance && isset($context->vars_in_scope[$var_id]) && $context->vars_in_scope[$var_id]->has_mixed()) {
                    if (!$context->collect_initializations && !$context->collect_mutations && $statements_analyzer->get_file_path() === $statements_analyzer->get_root_file_path() && (!($parent_source = $statements_analyzer->get_source()) instanceof Function_Like_Analyzer || !$parent_source->get_source() instanceof Trait_Analyzer)) {
                        $codebase->analyzer->decrement_mixed_count($statements_analyzer->get_file_path());
                    }
                    Issue_Buffer::remove($statements_analyzer->get_file_path(), 'MixedAssignment', $first_appearance->raw_file_start);
                }
                if (isset($op_vars_in_scope[$var_id])) {
                    $op_vars_in_scope[$var_id] = $op_vars_in_scope[$var_id]->set_properties(['from_docblock' => true]);
                }
            }
            $context->vars_in_scope = $op_vars_in_scope;
            $context->references_in_scope = $op_references_in_scope;
        }
        if ($changed_var_ids) {
            $simplified_clauses = Context::remove_reconciled_clauses($simplified_clauses, $changed_var_ids)[0];
        }
        $context->clauses = $simplified_clauses;
    }
    private static function check_function_call_purity(Statements_Analyzer $statements_analyzer, Codebase $codebase, Php_Parser\Node\Expr\Func_Call $stmt, Php_Parser\Node $function_name, Function_Call_Info $function_call_info, Context $context): void
    {
        $config = $codebase->config;
        if (!$context->collect_initializations && !$context->collect_mutations && ($context->mutation_free || $context->external_mutation_free || $codebase->find_unused_variables || !$config->remember_property_assignments_after_call || $statements_analyzer->get_source() instanceof Function_Like_Analyzer && $statements_analyzer->get_source()->track_mutations)) {
            $must_use = true;
            $callmap_function_pure = $function_call_info->function_id && $function_call_info->in_call_map ? $codebase->functions->is_call_map_function_pure($codebase, $statements_analyzer->node_data, $function_call_info->function_id, $stmt->is_first_class_callable() ? [] : $stmt->get_args(), $must_use) : null;
            if (!$function_call_info->in_call_map && $function_call_info->function_storage && !$function_call_info->function_storage->pure && !$function_call_info->function_storage->mutation_free || $callmap_function_pure === false) {
                if ($context->mutation_free || $context->external_mutation_free) {
                    Issue_Buffer::maybe_add(new Impure_Function_Call('Cannot call an impure function from a mutation-free context', new Code_Location($statements_analyzer, $function_name)), $statements_analyzer->get_suppressed_issues());
                } elseif ($statements_analyzer->get_source() instanceof Function_Like_Analyzer && $statements_analyzer->get_source()->track_mutations) {
                    $statements_analyzer->get_source()->inferred_has_mutation = true;
                    $statements_analyzer->get_source()->inferred_impure = true;
                }
                if (!$config->remember_property_assignments_after_call) {
                    $context->remove_mutable_object_vars();
                }
            } elseif ($function_call_info->function_id && ($function_call_info->function_storage && $function_call_info->function_storage->pure && !$function_call_info->function_storage->assertions && $must_use || $callmap_function_pure === true && $must_use) && $codebase->find_unused_variables && !$context->inside_conditional && !$context->inside_unset) {
                /**
                 * If a function is pure, and has the return type of 'no-return',
                 * it's okay to dismiss it's return value.
                 */
                if (!$context->inside_use() && !self::call_uses_by_reference_arguments($function_call_info, $stmt) && !($function_call_info->function_storage && $function_call_info->function_storage->return_type && $function_call_info->function_storage->return_type->is_never())) {
                    Issue_Buffer::maybe_add(new Unused_Function_Call('The call to ' . $function_call_info->function_id . ' is not used', new Code_Location($statements_analyzer, $function_name), $function_call_info->function_id), $statements_analyzer->get_suppressed_issues());
                } else {
                    $stmt->set_attribute('pure', true);
                }
            }
        }
    }
    private static function call_uses_by_reference_arguments(Function_Call_Info $function_call_info, Php_Parser\Node\Expr\Func_Call $stmt): bool
    {
        // If the function doesn't have any by-reference parameters
        // we shouldn't look any further.
        if (!$function_call_info->has_by_reference_parameters() || null === $function_call_info->function_params) {
            return false;
        }
        $parameters = $function_call_info->function_params;
        // If no arguments were passed
        if (0 === count($stmt->get_args())) {
            return false;
        }
        foreach ($stmt->get_args() as $index => $argument) {
            $parameter = null;
            if (null !== $argument->name) {
                $argument_name = $argument->name->to_string();
                foreach ($parameters as $param) {
                    if ($param->name === $argument_name) {
                        $parameter = $param;
                        break;
                    }
                }
            } else {
                $parameter = $parameters[$index] ?? null;
            }
            if ($parameter && $parameter->by_ref) {
                return true;
            }
        }
        return false;
    }
}