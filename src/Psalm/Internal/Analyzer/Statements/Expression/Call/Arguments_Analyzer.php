<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression\Call;

use InvalidArgumentException;
use Php_Parser;
use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Internal\Analyzer\Attributes_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Assignment\Instance_Property_Assignment_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Assignment_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Expression_Identifier;
use Psalm\Internal\Analyzer\Statements\Expression\Fetch\Array_Fetch_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Codebase\Constant_Type_Resolver;
use Psalm\Internal\Codebase\Functions;
use Psalm\Internal\Codebase\Internal_Call_Map_Handler;
use Psalm\Internal\Codebase\Taint_Flow_Graph;
use Psalm\Internal\Data_Flow\Taint_Sink;
use Psalm\Internal\Method_Identifier;
use Psalm\Internal\Stubs\Generator\Stubs_Generator;
use Psalm\Internal\Type\Comparator\Union_Type_Comparator;
use Psalm\Internal\Type\Template_Inferred_Type_Replacer;
use Psalm\Internal\Type\Template_Result;
use Psalm\Internal\Type\Template_Standin_Type_Replacer;
use Psalm\Internal\Type\Type_Expander;
use Psalm\Issue\Invalid_Named_Argument;
use Psalm\Issue\Invalid_Pass_By_Reference;
use Psalm\Issue\Possibly_Undefined_Variable;
use Psalm\Issue\Too_Few_Arguments;
use Psalm\Issue\Too_Many_Arguments;
use Psalm\Issue_Buffer;
use Psalm\Node\Virtual_Arg;
use Psalm\Storage\Class_Like_Storage;
use Psalm\Storage\Function_Like_Parameter;
use Psalm\Storage\Function_Like_Storage;
use Psalm\Storage\Method_Storage;
use Psalm\Type;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Callable;
use Psalm\Type\Atomic\T_Callable_Keyed_Array;
use Psalm\Type\Atomic\T_Closure;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Atomic\T_Literal_String;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Non_Empty_Array;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Union;
use UnexpectedValueException;
use function array_reduce;
use function array_reverse;
use function array_slice;
use function array_values;
use function assert;
use function count;
use function in_array;
use function is_string;
use function max;
use function min;
use function reset;
use function str_contains;
use function strtolower;
/**
 * @internal
 */
final class Arguments_Analyzer
{
    public const ARRAY_FILTERLIKE = ['array_filter', 'array_find', 'array_find_key', 'array_any', 'array_all'];
    /**
     * @param   list<PhpParser\Node\Arg>          $args
     * @param   array<int, FunctionLikeParameter>|null  $function_params
     * @return  false|null
     */
    public static function analyze(Statements_Analyzer $statements_analyzer, array $args, ?array $function_params, ?string $method_id, bool $allow_named_args, Context $context, ?Template_Result $template_result = null): ?bool
    {
        $last_param = $function_params ? $function_params[count($function_params) - 1] : null;
        // if this modifies the array type based on further args
        if (in_array($method_id, ['array_push', 'array_unshift'], true) && $function_params && isset($args[0]) && isset($args[1])) {
            if (Array_Function_Arguments_Analyzer::handle_addition($statements_analyzer, $args, $context, $method_id) === false) {
                return false;
            }
            return null;
        }
        if ($method_id === 'array_splice' && $function_params && count($args) > 1) {
            if (Array_Function_Arguments_Analyzer::handle_splice($statements_analyzer, $args, $context) === false) {
                return false;
            }
            return null;
        }
        if ($method_id === 'array_map') {
            $args = array_reverse($args, true);
        }
        foreach ($args as $argument_offset => $arg) {
            if ($function_params === null) {
                if (self::evaluate_arbitrary_param($statements_analyzer, $arg, $context) === false) {
                    return false;
                }
                continue;
            }
            $param = null;
            if ($arg->name && $allow_named_args) {
                foreach ($function_params as $candidate_param) {
                    if ($candidate_param->name === $arg->name->name) {
                        $param = $candidate_param;
                        break;
                    }
                }
                if ($last_param && $last_param->is_variadic) {
                    $param = $last_param;
                }
            } elseif ($argument_offset < count($function_params)) {
                $param = $function_params[$argument_offset];
            } elseif ($last_param && $last_param->is_variadic) {
                $param = $last_param;
            }
            $by_ref = $param && $param->by_ref;
            $by_ref_type = null;
            if ($by_ref) {
                $by_ref_type = $param->type ?: Type::get_mixed();
            }
            if ($by_ref && $by_ref_type && !($arg->value instanceof Php_Parser\Node\Expr\Closure || $arg->value instanceof Php_Parser\Node\Expr\Const_Fetch || $arg->value instanceof Php_Parser\Node\Expr\Class_Const_Fetch || $arg->value instanceof Php_Parser\Node\Expr\Func_Call || $arg->value instanceof Php_Parser\Node\Expr\Method_Call || $arg->value instanceof Php_Parser\Node\Expr\Static_Call || $arg->value instanceof Php_Parser\Node\Expr\New_ || $arg->value instanceof Php_Parser\Node\Expr\Assign || $arg->value instanceof Php_Parser\Node\Expr\Array_ || $arg->value instanceof Php_Parser\Node\Expr\Ternary || $arg->value instanceof Php_Parser\Node\Expr\Binary_Op)) {
                if (self::handle_by_ref_function_arg($statements_analyzer, $method_id, $argument_offset, $arg, $context) === false) {
                    return false;
                }
                continue;
            }
            $toggled_class_exists = false;
            if (in_array($method_id, ['class_exists', 'interface_exists', 'enum_exists', 'trait_exists'], true) && $argument_offset === 0 && !$context->inside_class_exists) {
                $context->inside_class_exists = true;
                $toggled_class_exists = true;
            }
            $high_order_template_result = null;
            $high_order_callable_info = $param ? High_Order_Function_Arg_Handler::get_callable_arg_info($context, $arg->value, $statements_analyzer, $param) : null;
            if ($param && $high_order_callable_info) {
                $high_order_template_result = High_Order_Function_Arg_Handler::remap_lower_bounds($statements_analyzer, $template_result ?? new Template_Result([], []), $high_order_callable_info, $param->type ?? Type::get_mixed());
            } elseif (($arg->value instanceof Php_Parser\Node\Expr\Closure || $arg->value instanceof Php_Parser\Node\Expr\Arrow_Function) && $param && !$arg->value->get_doc_comment()) {
                self::handle_closure_arg($statements_analyzer, $args, $method_id, $context, $template_result ?? new Template_Result([], []), $argument_offset, $arg, $param);
            }
            $was_inside_call = $context->inside_call;
            $context->inside_call = true;
            $was_inside_isset = $context->inside_isset;
            $context->inside_isset = false;
            if (Expression_Analyzer::analyze($statements_analyzer, $arg->value, $context, false, null, null, $high_order_template_result) === false) {
                $context->inside_isset = $was_inside_isset;
                $context->inside_call = $was_inside_call;
                return false;
            }
            $context->inside_isset = $was_inside_isset;
            $context->inside_call = $was_inside_call;
            if ($high_order_callable_info && $high_order_template_result) {
                High_Order_Function_Arg_Handler::enhance_callable_arg_type($context, $arg->value, $statements_analyzer, $high_order_callable_info, $high_order_template_result);
            }
            if ($argument_offset === 0 && in_array($method_id, self::ARRAY_FILTERLIKE, true) && count($args) === 2 || $argument_offset > 0 && $method_id === 'array_map' && count($args) >= 2) {
                self::handle_array_map_filter_array_arg($statements_analyzer, $method_id, $argument_offset, $arg, $context, $template_result);
            }
            $inferred_arg_type = $statements_analyzer->node_data->get_type($arg->value);
            if (null !== $inferred_arg_type && null !== $template_result && null !== $param && null !== $param->type && !$arg->unpack) {
                $codebase = $statements_analyzer->get_codebase();
                Template_Standin_Type_Replacer::fill_template_result($param->type, $template_result, $codebase, $statements_analyzer, $inferred_arg_type, $argument_offset, $context->self, $context->calling_method_id ?: $context->calling_function_id);
            }
            if ($toggled_class_exists) {
                $context->inside_class_exists = false;
            }
        }
        if ($method_id === "ReflectionClass::getattributes" || $method_id === "ReflectionClassConstant::getattributes" || $method_id === "ReflectionFunction::getattributes" || $method_id === "ReflectionMethod::getattributes" || $method_id === "ReflectionParameter::getattributes" || $method_id === "ReflectionProperty::getattributes") {
            Attributes_Analyzer::analyze_get_attributes($statements_analyzer, $method_id, array_values($args));
        }
        return null;
    }
    private static function handle_array_map_filter_array_arg(Statements_Analyzer $statements_analyzer, string $method_id, int $argument_offset, Php_Parser\Node\Arg $arg, Context $context, ?Template_Result &$template_result): void
    {
        $codebase = $statements_analyzer->get_codebase();
        $template_types = ['ArrayValue' . $argument_offset => [$method_id => Type::get_mixed()]];
        $replace_template_result = new Template_Result($template_types, []);
        $existing_type = $statements_analyzer->node_data->get_type($arg->value);
        Template_Standin_Type_Replacer::fill_template_result(new Union([new T_Array([Type::get_array_key(), new Union([new T_Template_Param('ArrayValue' . $argument_offset, Type::get_mixed(), $method_id)])])]), $replace_template_result, $codebase, $statements_analyzer, $existing_type, $argument_offset, $context->self, $context->calling_method_id ?: $context->calling_function_id);
        if ($replace_template_result->lower_bounds) {
            if (!$template_result) {
                $template_result = new Template_Result([], []);
            }
            $template_result->lower_bounds += $replace_template_result->lower_bounds;
        }
    }
    /**
     * @param   array<int, PhpParser\Node\Arg>  $args
     */
    private static function handle_closure_arg(Statements_Analyzer $statements_analyzer, array $args, ?string $method_id, Context $context, Template_Result $template_result, int $argument_offset, Php_Parser\Node\Arg $arg, Function_Like_Parameter $param): void
    {
        if (!$param->type) {
            return;
        }
        $codebase = $statements_analyzer->get_codebase();
        if ($argument_offset === 1 && in_array($method_id, self::ARRAY_FILTERLIKE, true) && count($args) === 2 || $argument_offset === 0 && $method_id === 'array_map' && count($args) >= 2) {
            $function_like_params = [];
            foreach ($template_result->lower_bounds as $template_name => $_) {
                $t = new Union([new T_Template_Param($template_name, Type::get_mixed(), $method_id)]);
                $function_like_params[] = new Function_Like_Parameter('function', false, $t, $t);
            }
            $replaced_type = new Union([new T_Callable('callable', array_reverse($function_like_params))]);
        } else {
            $replaced_type = $param->type;
        }
        $new_bounds = $template_result->template_types;
        foreach ($template_result->lower_bounds as $k => $template_map) {
            $new_bounds[$k] = [];
            foreach ($template_map as $kk => $lower_bounds) {
                $new_bounds[$k][$kk] = Template_Standin_Type_Replacer::get_most_specific_type_from_bounds($lower_bounds, $codebase);
            }
        }
        $replace_template_result = new Template_Result($new_bounds, []);
        unset($new_bounds);
        $replaced_type = Template_Standin_Type_Replacer::replace($replaced_type, $replace_template_result, $codebase, $statements_analyzer, null, null, null, $context->calling_method_id ?: $context->calling_function_id);
        $replaced_type = Template_Inferred_Type_Replacer::replace($replaced_type, $replace_template_result, $codebase);
        $closure_id = strtolower($statements_analyzer->get_file_path()) . ':' . $arg->value->get_line() . ':' . (int) $arg->value->get_attribute('startFilePos') . ':-:closure';
        try {
            $closure_storage = $codebase->get_closure_storage($statements_analyzer->get_file_path(), $closure_id);
        } catch (UnexpectedValueException) {
            return;
        }
        foreach ($closure_storage->params as $closure_param_offset => $param_storage) {
            $param_type_inferred = $param_storage->type_inferred;
            $newly_inferred_type = null;
            $has_different_docblock_type = false;
            if ($param_storage->type && !$param_type_inferred) {
                if ($param_storage->type !== $param_storage->signature_type) {
                    $has_different_docblock_type = true;
                }
            }
            if (!$has_different_docblock_type) {
                foreach ($replaced_type->get_atomic_types() as $replaced_type_part) {
                    if ($replaced_type_part instanceof T_Callable || $replaced_type_part instanceof T_Closure) {
                        if (isset($replaced_type_part->params[$closure_param_offset]->type)) {
                            $replaced_param_type = $replaced_type_part->params[$closure_param_offset]->type;
                            if ($replaced_param_type->has_template()) {
                                $replaced_param_type = Type_Expander::expand_union($codebase, $replaced_param_type, null, null, null, true, false, false, true, true);
                            }
                            if ($param_storage->type && !$param_type_inferred) {
                                $type_match_found = Union_Type_Comparator::is_contained_by($codebase, $replaced_param_type, $param_storage->type);
                                if (!$type_match_found) {
                                    continue;
                                }
                            }
                            $newly_inferred_type = Type::combine_union_types($newly_inferred_type, $replaced_param_type, $codebase);
                        }
                    }
                }
            }
            if ($newly_inferred_type) {
                $param_storage->type = $newly_inferred_type;
                $param_storage->type_inferred = true;
            }
            if ($param_storage->type && ($method_id === 'array_map' || in_array($method_id, self::ARRAY_FILTERLIKE, true))) {
                $temp = Type::get_mixed();
                Array_Fetch_Analyzer::taint_array_fetch($statements_analyzer, $args[1 - $argument_offset]->value, null, $param_storage->type, $temp);
            }
        }
    }
    /**
     * @param   list<PhpParser\Node\Arg>  $args
     * @param   array<int,FunctionLikeParameter>        $function_params
     * @return  false|null
     * @psalm-suppress ComplexMethod there's just not much that can be done about this
     */
    public static function check_arguments_match(Statements_Analyzer $statements_analyzer, array $args, string|Method_Identifier|null $method_id, array $function_params, ?Function_Like_Storage $function_storage, ?Class_Like_Storage $class_storage, Template_Result $template_result, Code_Location $code_location, Context $context): ?bool
    {
        $in_call_map = $method_id && Internal_Call_Map_Handler::in_call_map((string) $method_id);
        $cased_method_id = (string) $method_id;
        $is_variadic = false;
        $fq_class_name = null;
        $codebase = $statements_analyzer->get_codebase();
        if ($method_id) {
            if ($method_id instanceof Method_Identifier) {
                $fq_class_name = $method_id->fq_class_name;
            }
            if ($function_storage) {
                $is_variadic = $function_storage->variadic;
            } elseif (is_string($method_id)) {
                $is_variadic = Functions::is_variadic($codebase, strtolower($method_id), $statements_analyzer->get_root_file_path());
            } else {
                $is_variadic = $codebase->methods->is_variadic($method_id);
            }
        }
        if ($method_id instanceof Method_Identifier) {
            $cased_method_id = $codebase->methods->get_cased_method_id($method_id);
        } elseif ($function_storage) {
            $cased_method_id = $function_storage->cased_name;
        }
        $calling_class_storage = $class_storage;
        $static_fq_class_name = $fq_class_name;
        $self_fq_class_name = $fq_class_name;
        if ($method_id instanceof Method_Identifier) {
            $declaring_method_id = $codebase->methods->get_declaring_method_id($method_id);
            if ($declaring_method_id && (string) $declaring_method_id !== (string) $method_id) {
                $self_fq_class_name = $declaring_method_id->fq_class_name;
                $class_storage = $codebase->classlike_storage_provider->get($self_fq_class_name);
            }
            $appearing_method_id = $codebase->methods->get_appearing_method_id($method_id);
            if ($appearing_method_id && $declaring_method_id !== $appearing_method_id) {
                $self_fq_class_name = $appearing_method_id->fq_class_name;
            }
        }
        if ($function_params && !$is_variadic) {
            foreach ($function_params as $function_param) {
                $is_variadic = $is_variadic || $function_param->is_variadic;
            }
        }
        $has_packed_var = false;
        foreach ($args as $arg) {
            if ($arg->unpack) {
                $has_packed_var = true;
            }
        }
        $last_param = $function_params ? $function_params[count($function_params) - 1] : null;
        $class_generic_params = [];
        foreach ($template_result->lower_bounds as $template_name => $type_map) {
            foreach ($type_map as $class => $lower_bounds) {
                if (count($lower_bounds) === 1) {
                    $class_generic_params[$template_name][$class] = reset($lower_bounds)->type;
                }
            }
        }
        if ($function_storage) {
            $template_result = self::get_provisional_template_result_for_function_like($statements_analyzer, $codebase, $context, $class_storage, $self_fq_class_name, $calling_class_storage, $function_storage, $class_generic_params, $template_result, $args, $function_params, $last_param);
        }
        $function_param_count = count($function_params);
        if (count($function_params) > count($args) && !$has_packed_var) {
            for ($i = count($args), $i_max = count($function_params); $i < $i_max; $i++) {
                if ($function_params[$i]->default_type && $function_params[$i]->type && $function_params[$i]->type->has_template()) {
                    if ($function_params[$i]->default_type instanceof Union) {
                        $default_type = $function_params[$i]->default_type;
                    } else {
                        $default_type_atomic = Constant_Type_Resolver::resolve($codebase->classlikes, $function_params[$i]->default_type, $statements_analyzer);
                        $default_type = new Union([$default_type_atomic]);
                    }
                    if ($default_type->has_literal_value()) {
                        Argument_Analyzer::check_argument_matches($statements_analyzer, $cased_method_id, $method_id instanceof Method_Identifier ? $method_id : null, $self_fq_class_name, $static_fq_class_name, $code_location, $function_params[$i], $i, $i, $function_storage->allow_named_arg_calls ?? true, new Virtual_Arg(Stubs_Generator::get_expression_from_type($default_type)), $default_type, $context, $class_generic_params, $template_result, $function_storage->specialize_call ?? true, $in_call_map);
                    }
                }
            }
        }
        if (($method_id === 'preg_match_all' || $method_id === 'preg_match') && count($args) > 3) {
            $args = array_reverse($args, true);
        }
        $arg_function_params = [];
        $matched_args = [];
        $named_args_was_used = false;
        foreach ($args as $argument_offset => $arg) {
            if ($named_args_was_used && !$arg->name) {
                Issue_Buffer::maybe_add(new Invalid_Named_Argument('Cannot use positional argument after named argument', new Code_Location($statements_analyzer, $arg), (string) $method_id), $statements_analyzer->get_suppressed_issues());
            }
            if ($arg->unpack) {
                if ($function_param_count > $argument_offset) {
                    for ($i = $argument_offset; $i < $function_param_count; $i++) {
                        $arg_function_params[$argument_offset][] = $function_params[$i];
                    }
                }
                if (($arg_value_type = $statements_analyzer->node_data->get_type($arg->value)) && $arg_value_type->has_array()) {
                    /**
                     * @var TArray|TKeyedArray
                     */
                    $array_type = $arg_value_type->get_array();
                    if ($array_type instanceof T_Keyed_Array) {
                        $array_type = $array_type->get_generic_array_type();
                        $key_types = $array_type->type_params[0]->get_atomic_types();
                        foreach ($key_types as $key_type) {
                            if (!$key_type instanceof T_Literal_String) {
                                continue;
                            }
                            if ($function_storage && !$function_storage->allow_named_arg_calls) {
                                continue;
                            }
                            $param_found = false;
                            foreach ($function_params as $candidate_param) {
                                if ($candidate_param->name === $key_type->value || $candidate_param->is_variadic) {
                                    if ($candidate_param->name === $key_type->value) {
                                        if (isset($matched_args[$candidate_param->name])) {
                                            Issue_Buffer::maybe_add(new Invalid_Named_Argument('Parameter $' . $key_type->value . ' has already been used in ' . ($cased_method_id ?: $method_id), new Code_Location($statements_analyzer, $arg), (string) $method_id), $statements_analyzer->get_suppressed_issues());
                                        }
                                        $matched_args[$candidate_param->name] = true;
                                    }
                                    $param_found = true;
                                    break;
                                }
                            }
                            if (!$param_found) {
                                Issue_Buffer::maybe_add(new Invalid_Named_Argument('Parameter $' . $key_type->value . ' does not exist on function ' . ($cased_method_id ?: $method_id), new Code_Location($statements_analyzer, $arg), (string) $method_id), $statements_analyzer->get_suppressed_issues());
                            }
                        }
                    }
                }
            } elseif ($arg->name && (!$function_storage || $function_storage->allow_named_arg_calls)) {
                $named_args_was_used = true;
                foreach ($function_params as $candidate_param) {
                    if ($candidate_param->name === $arg->name->name || $candidate_param->is_variadic) {
                        if ($candidate_param->name === $arg->name->name) {
                            if (isset($matched_args[$candidate_param->name])) {
                                Issue_Buffer::maybe_add(new Invalid_Named_Argument('Parameter $' . $arg->name->name . ' has already been used in ' . ($cased_method_id ?: $method_id), new Code_Location($statements_analyzer, $arg->name), (string) $method_id), $statements_analyzer->get_suppressed_issues());
                            }
                            $matched_args[$candidate_param->name] = true;
                        }
                        $arg_function_params[$argument_offset] = [$candidate_param];
                        break;
                    }
                }
                if (!isset($arg_function_params[$argument_offset])) {
                    Issue_Buffer::maybe_add(new Invalid_Named_Argument('Parameter $' . $arg->name->name . ' does not exist on function ' . ($cased_method_id ?: $method_id), new Code_Location($statements_analyzer, $arg->name), (string) $method_id), $statements_analyzer->get_suppressed_issues());
                }
            } elseif ($function_param_count > $argument_offset) {
                $arg_function_params[$argument_offset] = [$function_params[$argument_offset]];
                $matched_args[$function_params[$argument_offset]->name] = true;
            } elseif ($last_param && $last_param->is_variadic) {
                $arg_function_params[$argument_offset] = [$last_param];
                $matched_args[$last_param->name] = true;
            }
        }
        foreach ($args as $argument_offset => $arg) {
            if (!isset($arg_function_params[$argument_offset])) {
                continue;
            }
            if ($arg_function_params[$argument_offset][0]->by_ref && $method_id !== 'extract') {
                if (self::handle_possibly_matching_by_ref_param($statements_analyzer, $codebase, (string) $method_id, $cased_method_id, $last_param, $function_params, $argument_offset, $arg, $context, $template_result) === false) {
                    return null;
                }
            }
            $arg_value_type = $statements_analyzer->node_data->get_type($arg->value);
            foreach ($arg_function_params[$argument_offset] as $i => $function_param) {
                if (Argument_Analyzer::check_argument_matches($statements_analyzer, $cased_method_id, $method_id instanceof Method_Identifier ? $method_id : null, $self_fq_class_name, $static_fq_class_name, $code_location, $function_param, $argument_offset + $i, $i, $function_storage->allow_named_arg_calls ?? true, $arg, $arg_value_type, $context, $class_generic_params, $template_result, $function_storage->specialize_call ?? true, $in_call_map) === false) {
                    return false;
                }
            }
        }
        if ($statements_analyzer->data_flow_graph instanceof Taint_Flow_Graph && $cased_method_id) {
            foreach ($args as $argument_offset => $_) {
                if (!isset($arg_function_params[$argument_offset])) {
                    continue;
                }
                foreach ($arg_function_params[$argument_offset] as $function_param) {
                    if ($function_param->sinks) {
                        if (!$function_storage || $function_storage->specialize_call) {
                            $sink = Taint_Sink::get_for_method_argument($cased_method_id, $cased_method_id, $argument_offset, $function_param->location, $code_location);
                        } else {
                            $sink = Taint_Sink::get_for_method_argument($cased_method_id, $cased_method_id, $argument_offset, $function_param->location);
                        }
                        $sink->taints = $function_param->sinks;
                        $statements_analyzer->data_flow_graph->add_sink($sink);
                    }
                }
            }
        }
        $f = in_array($method_id, self::ARRAY_FILTERLIKE, true);
        if ($f || $method_id === 'array_map') {
            assert(is_string($method_id));
            if (!$f && count($args) < 2) {
                Issue_Buffer::maybe_add(new Too_Few_Arguments('Too few arguments for ' . $method_id, $code_location, $method_id), $statements_analyzer->get_suppressed_issues());
            } elseif ($f && count($args) < 1) {
                Issue_Buffer::maybe_add(new Too_Few_Arguments('Too few arguments for ' . $method_id, $code_location, $method_id), $statements_analyzer->get_suppressed_issues());
            }
            Array_Function_Arguments_Analyzer::check_arguments_match($statements_analyzer, $context, $args, $method_id, $context->check_functions);
            return null;
        }
        if ($method_id === 'get_class' && $args === []) {
            //get_class without args only works when inside a class
            if (!$context->self) {
                Issue_Buffer::maybe_add(new Too_Few_Arguments('Cannot call get_class() without argument outside of class scope', $code_location, $method_id), $statements_analyzer->get_suppressed_issues());
                return null;
            }
        }
        self::check_arg_count($statements_analyzer, $codebase, $function_storage, $context, $template_result, $is_variadic, $args, $function_params, $in_call_map, $method_id, $cased_method_id, $code_location);
        return null;
    }
    /**
     * @param  array<int, FunctionLikeParameter> $function_params
     * @return false|null
     */
    private static function handle_possibly_matching_by_ref_param(Statements_Analyzer $statements_analyzer, Codebase $codebase, string $method_id, ?string $cased_method_id, ?Function_Like_Parameter $last_param, array $function_params, int $argument_offset, Php_Parser\Node\Arg $arg, Context $context, ?Template_Result $template_result): ?bool
    {
        if ($arg->value instanceof Php_Parser\Node\Scalar || $arg->value instanceof Php_Parser\Node\Expr\Cast || $arg->value instanceof Php_Parser\Node\Expr\Array_ || $arg->value instanceof Php_Parser\Node\Expr\Class_Const_Fetch || $arg->value instanceof Php_Parser\Node\Expr\Binary_Op || $arg->value instanceof Php_Parser\Node\Expr\Ternary || ($arg->value instanceof Php_Parser\Node\Expr\Const_Fetch || $arg->value instanceof Php_Parser\Node\Expr\Func_Call || $arg->value instanceof Php_Parser\Node\Expr\Method_Call || $arg->value instanceof Php_Parser\Node\Expr\Static_Call) && (!($arg_value_type = $statements_analyzer->node_data->get_type($arg->value)) || !$arg_value_type->by_ref)) {
            Issue_Buffer::maybe_add(new Invalid_Pass_By_Reference('Parameter ' . ($argument_offset + 1) . ' of ' . $cased_method_id . ' expects a variable', new Code_Location($statements_analyzer->get_source(), $arg->value)), $statements_analyzer->get_suppressed_issues());
            return false;
        }
        if (!in_array($method_id, ['ksort', 'asort', 'krsort', 'arsort', 'natcasesort', 'natsort', 'reset', 'end', 'next', 'prev', 'array_pop', 'array_shift', 'array_push', 'array_unshift', 'socket_select', 'array_splice'], true)) {
            $by_ref_type = null;
            $by_ref_out_type = null;
            $check_null_ref = true;
            if ($last_param) {
                if ($arg->name !== null) {
                    $function_param = array_reduce($function_params, static function (?Function_Like_Parameter $function_param, Function_Like_Parameter $param) use ($arg) {
                        if ($param->name === $arg->name->name) {
                            return $param;
                        }
                        return $function_param;
                    });
                    if ($function_param === null) {
                        return false;
                    }
                } elseif ($argument_offset < count($function_params)) {
                    $function_param = $function_params[$argument_offset];
                } else {
                    $function_param = $last_param;
                }
                if ($function_param->type) {
                    $by_ref_type = $function_param->type;
                }
                if ($function_param->out_type) {
                    $by_ref_out_type = $function_param->out_type;
                }
                if ($by_ref_type && $by_ref_type->is_nullable()) {
                    $check_null_ref = false;
                }
                if ($template_result && $by_ref_type) {
                    $original_by_ref_type = $by_ref_type;
                    $by_ref_type = Template_Standin_Type_Replacer::replace($by_ref_type, $template_result, $codebase, $statements_analyzer, $statements_analyzer->node_data->get_type($arg->value), $argument_offset, $context->self, $context->calling_method_id ?: $context->calling_function_id);
                    if ($template_result->lower_bounds) {
                        $original_by_ref_type = Template_Inferred_Type_Replacer::replace($original_by_ref_type, $template_result, $codebase);
                        $by_ref_type = $original_by_ref_type;
                    }
                }
                if ($template_result && $by_ref_out_type) {
                    $original_by_ref_out_type = $by_ref_out_type;
                    $by_ref_out_type = Template_Standin_Type_Replacer::replace($by_ref_out_type, $template_result, $codebase, $statements_analyzer, $statements_analyzer->node_data->get_type($arg->value), $argument_offset, $context->self, $context->calling_method_id ?: $context->calling_function_id);
                    if ($template_result->lower_bounds) {
                        $original_by_ref_out_type = Template_Inferred_Type_Replacer::replace($original_by_ref_out_type, $template_result, $codebase);
                        $by_ref_out_type = $original_by_ref_out_type;
                    }
                }
                if ($by_ref_type && $function_param->is_variadic && $arg->unpack) {
                    $by_ref_type = new Union([new T_Array([Type::get_int(), $by_ref_type])]);
                }
            }
            $by_ref_type = $by_ref_type ?: Type::get_mixed();
            Assignment_Analyzer::assign_by_ref_param($statements_analyzer, $arg->value, $by_ref_type, $by_ref_out_type ?: $by_ref_type, $context, $method_id && (str_contains($method_id, '::') || !Internal_Call_Map_Handler::in_call_map($method_id)), $check_null_ref);
        }
        return null;
    }
    /**
     * @return false|null
     */
    private static function evaluate_arbitrary_param(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Arg $arg, Context $context): ?bool
    {
        // there are a bunch of things we want to evaluate even when we don't
        // know what function/method is being called
        if ($arg->value instanceof Php_Parser\Node\Expr\Closure || $arg->value instanceof Php_Parser\Node\Expr\Const_Fetch || $arg->value instanceof Php_Parser\Node\Expr\Class_Const_Fetch || $arg->value instanceof Php_Parser\Node\Expr\Func_Call || $arg->value instanceof Php_Parser\Node\Expr\Method_Call || $arg->value instanceof Php_Parser\Node\Expr\Static_Call || $arg->value instanceof Php_Parser\Node\Expr\Arrow_Function || $arg->value instanceof Php_Parser\Node\Expr\New_ || $arg->value instanceof Php_Parser\Node\Expr\Cast || $arg->value instanceof Php_Parser\Node\Expr\Assign || $arg->value instanceof Php_Parser\Node\Expr\Array_Dim_Fetch || $arg->value instanceof Php_Parser\Node\Expr\Property_Fetch || $arg->value instanceof Php_Parser\Node\Expr\Array_ || $arg->value instanceof Php_Parser\Node\Expr\Binary_Op || $arg->value instanceof Php_Parser\Node\Expr\Ternary || $arg->value instanceof Php_Parser\Node\Scalar\Interpolated_String || $arg->value instanceof Php_Parser\Node\Expr\Post_Inc || $arg->value instanceof Php_Parser\Node\Expr\Post_Dec || $arg->value instanceof Php_Parser\Node\Expr\Pre_Inc || $arg->value instanceof Php_Parser\Node\Expr\Pre_Dec) {
            $was_inside_call = $context->inside_call;
            $context->inside_call = true;
            if (Expression_Analyzer::analyze($statements_analyzer, $arg->value, $context) === false) {
                $context->inside_call = $was_inside_call;
                return false;
            }
            $context->inside_call = $was_inside_call;
        }
        if ($arg->value instanceof Php_Parser\Node\Expr\Property_Fetch && $arg->value->name instanceof Php_Parser\Node\Identifier) {
            $var_id = '$' . $arg->value->name->name;
        } else {
            $var_id = Expression_Identifier::get_var_id($arg->value, $statements_analyzer->get_fqcln(), $statements_analyzer);
        }
        if ($var_id) {
            if ($arg->value instanceof Php_Parser\Node\Expr\Variable) {
                $statements_analyzer->register_possibly_undefined_variable($var_id, $arg->value);
            }
            if (!$context->has_variable($var_id) || $context->vars_in_scope[$var_id]->is_null()) {
                if (!isset($context->vars_in_scope[$var_id]) && $arg->value instanceof Php_Parser\Node\Expr\Variable) {
                    Issue_Buffer::maybe_add(new Possibly_Undefined_Variable('Variable ' . $var_id . ' must be defined prior to use within an unknown function or method', new Code_Location($statements_analyzer->get_source(), $arg->value)), $statements_analyzer->get_suppressed_issues());
                }
                // we don't know if it exists, assume it's passed by reference
                $context->vars_in_scope[$var_id] = Type::get_mixed();
                $context->vars_possibly_in_scope[$var_id] = true;
            } else {
                $was_inside_call = $context->inside_call;
                $context->inside_call = true;
                Expression_Analyzer::analyze($statements_analyzer, $arg->value, $context);
                $context->inside_call = $was_inside_call;
                $context->remove_var_from_conflicting_clauses($var_id, $context->vars_in_scope[$var_id], $statements_analyzer);
                $t = $context->vars_in_scope[$var_id]->get_builder();
                foreach ($t->get_atomic_types() as $type) {
                    if ($type instanceof T_Array && $type->is_empty_array()) {
                        $t->remove_type('array');
                        $t->add_type(new T_Array([Type::get_array_key(), Type::get_mixed()]));
                    }
                }
                $context->vars_in_scope[$var_id] = $t->freeze();
            }
        }
        return null;
    }
    private static function handle_by_ref_readonly_arg(Statements_Analyzer $statements_analyzer, Context $context, Php_Parser\Node\Expr\Property_Fetch $stmt, string $fq_class_name, string $prop_name): void
    {
        $property_id = $fq_class_name . '::$' . $prop_name;
        $codebase = $statements_analyzer->get_codebase();
        $declaring_property_class = (string) $codebase->properties->get_declaring_class_for_property($property_id, true, $statements_analyzer);
        try {
            $declaring_class_storage = $codebase->classlike_storage_provider->get($declaring_property_class);
        } catch (InvalidArgumentException) {
            return;
        }
        if (isset($declaring_class_storage->properties[$prop_name])) {
            $property_storage = $declaring_class_storage->properties[$prop_name];
            Instance_Property_Assignment_Analyzer::track_property_impurity($statements_analyzer, $stmt, $property_id, $property_storage, $declaring_class_storage, $context);
        }
    }
    /**
     * @return false|null
     */
    private static function handle_by_ref_function_arg(Statements_Analyzer $statements_analyzer, ?string $method_id, int $argument_offset, Php_Parser\Node\Arg $arg, Context $context): ?bool
    {
        $var_id = Expression_Identifier::get_var_id($arg->value, $statements_analyzer->get_fqcln(), $statements_analyzer);
        $builtin_array_functions = ['ksort', 'asort', 'krsort', 'arsort', 'natcasesort', 'natsort', 'reset', 'end', 'next', 'prev', 'array_pop', 'array_shift', 'extract'];
        if ($arg->value instanceof Php_Parser\Node\Expr\Property_Fetch && $arg->value->name instanceof Php_Parser\Node\Identifier) {
            $prop_name = $arg->value->name->name;
            if (!empty($statements_analyzer->get_fqcln())) {
                $fq_class_name = $statements_analyzer->get_fqcln();
                self::handle_by_ref_readonly_arg($statements_analyzer, $context, $arg->value, $fq_class_name, $prop_name);
            } else {
                // @todo atm only works for simple fetch, $a->foo, not $a->foo->bar
                // I guess there's a function to do this, but I couldn't locate it
                $var_id = Expression_Identifier::get_var_id($arg->value->var, $statements_analyzer->get_fqcln(), $statements_analyzer);
                if ($var_id && isset($context->vars_in_scope[$var_id])) {
                    foreach ($context->vars_in_scope[$var_id]->get_atomic_types() as $atomic_type) {
                        if ($atomic_type instanceof T_Named_Object) {
                            $fq_class_name = $atomic_type->value;
                            self::handle_by_ref_readonly_arg($statements_analyzer, $context, $arg->value, $fq_class_name, $prop_name);
                        }
                    }
                }
            }
        }
        if ($var_id && isset($context->vars_in_scope[$var_id]) || $method_id && in_array($method_id, $builtin_array_functions, true)) {
            $was_inside_assignment = $context->inside_assignment;
            $context->inside_assignment = true;
            // if the variable is in scope, get or we're in a special array function,
            // figure out its type before proceeding
            if (Expression_Analyzer::analyze($statements_analyzer, $arg->value, $context) === false) {
                $context->inside_assignment = $was_inside_assignment;
                return false;
            }
            $context->inside_assignment = $was_inside_assignment;
        }
        // special handling for array sort
        if ($argument_offset === 0 && $method_id && in_array($method_id, $builtin_array_functions, true)) {
            if (in_array($method_id, ['array_pop', 'array_shift'], true)) {
                Array_Function_Arguments_Analyzer::handle_by_ref_array_adjustment($statements_analyzer, $arg, $context, $method_id === 'array_shift');
                return null;
            }
            // noops
            if (in_array($method_id, ['reset', 'end', 'next', 'prev', 'ksort'], true)) {
                return null;
            }
            if (($arg_value_type = $statements_analyzer->node_data->get_type($arg->value)) && $arg_value_type->has_array()) {
                /**
                 * @var TArray|TKeyedArray
                 */
                $array_type = $arg_value_type->get_array();
                if ($array_type instanceof T_Keyed_Array) {
                    $array_type = $array_type->get_generic_array_type();
                }
                $by_ref_type = new Union([$array_type]);
                Assignment_Analyzer::assign_by_ref_param($statements_analyzer, $arg->value, $by_ref_type, $by_ref_type, $context, false);
                return null;
            }
        }
        if ($method_id === 'socket_select') {
            if (Expression_Analyzer::analyze($statements_analyzer, $arg->value, $context) === false) {
                return false;
            }
        }
        if (!$arg->value instanceof Php_Parser\Node\Expr\Variable) {
            $suppressed_issues = $statements_analyzer->get_suppressed_issues();
            if (!in_array('EmptyArrayAccess', $suppressed_issues, true)) {
                $statements_analyzer->add_suppressed_issues(['EmptyArrayAccess']);
            }
            $v = Expression_Analyzer::analyze($statements_analyzer, $arg->value, $context);
            if (!in_array('EmptyArrayAccess', $suppressed_issues, true)) {
                $statements_analyzer->remove_suppressed_issues(['EmptyArrayAccess']);
            }
            if ($v === false) {
                return false;
            }
        }
        return null;
    }
    /**
     * @param   list<PhpParser\Node\Arg> $args
     * @param   array<int,FunctionLikeParameter>        $function_params
     * @param   array<string, array<string, Union>>  $class_generic_params
     */
    private static function get_provisional_template_result_for_function_like(Statements_Analyzer $statements_analyzer, Codebase $codebase, Context $context, ?Class_Like_Storage $class_storage, ?string $self_fq_class_name, ?Class_Like_Storage $calling_class_storage, Function_Like_Storage $function_storage, array $class_generic_params, ?Template_Result $template_result, array $args, array $function_params, ?Function_Like_Parameter $last_param): ?Template_Result
    {
        $template_types = Call_Analyzer::get_template_types_for_call($codebase, $class_storage, $self_fq_class_name, $calling_class_storage, $function_storage->template_types ?: [], $class_generic_params);
        if (!$template_types) {
            return null;
        }
        if (!$template_result) {
            return new Template_Result($template_types, []);
        }
        if (!$template_result->template_types) {
            $template_result->template_types = $template_types;
        }
        foreach ($args as $argument_offset => $arg) {
            $function_param = null;
            if ($arg->name && $function_storage->allow_named_arg_calls) {
                foreach ($function_params as $candidate_param) {
                    if ($candidate_param->name === $arg->name->name) {
                        $function_param = $candidate_param;
                        break;
                    }
                }
            } elseif ($argument_offset < count($function_params)) {
                $function_param = $function_params[$argument_offset];
            } elseif ($last_param && $last_param->is_variadic) {
                $function_param = $last_param;
            }
            if (!$function_param) {
                continue;
            }
            if (!$function_param->type) {
                continue;
            }
            $arg_value_type = $statements_analyzer->node_data->get_type($arg->value);
            if (!$arg_value_type) {
                continue;
            }
            $fleshed_out_param_type = Type_Expander::expand_union($codebase, $function_param->type, $class_storage->name ?? null, $calling_class_storage->name ?? null, null, true, false, $calling_class_storage->final ?? false);
            Template_Standin_Type_Replacer::fill_template_result($fleshed_out_param_type, $template_result, $codebase, $statements_analyzer, $arg_value_type, $argument_offset, $context->self, $context->calling_method_id ?: $context->calling_function_id, false);
        }
        return $template_result;
    }
    /**
     * @param   array<int, PhpParser\Node\Arg>  $args
     * @param   array<int,FunctionLikeParameter>        $function_params
     */
    private static function check_arg_count(Statements_Analyzer $statements_analyzer, Codebase $codebase, ?Function_Like_Storage $function_storage, Context $context, ?Template_Result $template_result, bool $is_variadic, array $args, array $function_params, bool $in_call_map, string|Method_Identifier|null $method_id, ?string $cased_method_id, Code_Location $code_location): void
    {
        if (!$is_variadic && count($args) > count($function_params) && (!count($function_params) || $function_params[count($function_params) - 1]->name !== '...=') && ($in_call_map || !$function_storage instanceof Method_Storage || $function_storage->is_static || $method_id instanceof Method_Identifier && $method_id->method_name === '__construct')) {
            Issue_Buffer::maybe_add(new Too_Many_Arguments('Too many arguments for ' . ($cased_method_id ?: $method_id) . ' - expecting ' . count($function_params) . ' but saw ' . count($args), $code_location, (string) $method_id), $statements_analyzer->get_suppressed_issues());
            return;
        }
        if (count($args) < count($function_params)) {
            //we're gonna loop over given args and unset them from the function_params.
            // If some mandatory params are left at the end, we'll throw an error
            foreach ($args as $arg) {
                // when the argument is not named, we can remove the params in order
                if ($arg->name === null) {
                    // if we're unpacking, we try to unset the exact number of params, if we can't we give up and return
                    if ($arg->unpack) {
                        $arg_value_type = $statements_analyzer->node_data->get_type($arg->value);
                        if (!$arg_value_type || !$arg_value_type->has_array()) {
                            return;
                        }
                        if ($arg_value_type->is_single() && ($atomic_arg_type = $arg_value_type->get_single_atomic()) && $atomic_arg_type instanceof T_Keyed_Array && !$atomic_arg_type->is_list) {
                            //if we have a single shape, we'll check param names
                            foreach ($atomic_arg_type->properties as $property_name => $_property_type) {
                                foreach ($function_params as $k => $param) {
                                    if ($param->name === $property_name) {
                                        unset($function_params[$k]);
                                    }
                                }
                            }
                            continue;
                        }
                        foreach ($arg_value_type->get_atomic_types() as $atomic_arg_type) {
                            $packed_var_definite_args_tmp = [];
                            if ($atomic_arg_type instanceof T_Callable_Keyed_Array) {
                                $packed_var_definite_args_tmp[] = 2;
                            } elseif ($atomic_arg_type instanceof T_Keyed_Array) {
                                if ($atomic_arg_type->fallback_params !== null) {
                                    return;
                                }
                                if (!$atomic_arg_type->all_shape_keys_always_defined()) {
                                    return;
                                }
                                //we did not return. The number of packed params is the number of properties
                                $packed_var_definite_args_tmp[] = count($atomic_arg_type->properties);
                            } elseif ($atomic_arg_type instanceof T_Non_Empty_Array) {
                                if ($atomic_arg_type->count === null) {
                                    return;
                                }
                                $packed_var_definite_args_tmp[] = $atomic_arg_type->count;
                            } elseif ($atomic_arg_type instanceof T_Array && $atomic_arg_type->type_params[1]->is_never()) {
                                $packed_var_definite_args_tmp[] = 0;
                            } else {
                                return;
                            }
                            if (min($packed_var_definite_args_tmp) === max($packed_var_definite_args_tmp)) {
                                //we have a stable number of params
                                $packed_var_definite_args = $packed_var_definite_args_tmp[0];
                            } else {
                                return;
                            }
                        }
                    } else {
                        //if we're not unpacking, we remove the first param
                        $packed_var_definite_args = 1;
                    }
                    $function_params = array_slice($function_params, $packed_var_definite_args);
                    continue;
                }
                foreach ($function_params as $k => $param) {
                    if ($param->name === $arg->name->name) {
                        unset($function_params[$k]);
                        continue;
                    }
                }
            }
            //we're now left with an array of params that were not passed.
            // If they're mandatory, throw an error. Otherwise, we compute the default value
            foreach ($function_params as $i => $param) {
                if (!$param->is_optional && !$param->is_variadic) {
                    Issue_Buffer::maybe_add(new Too_Few_Arguments('Too few arguments for ' . $cased_method_id . ' - expecting ' . $param->name . ' to be passed', $code_location, (string) $method_id), $statements_analyzer->get_suppressed_issues());
                    continue;
                }
                if ($param->type && $param->default_type && !$param->is_variadic && $template_result) {
                    if ($param->default_type instanceof Union) {
                        $default_type = $param->default_type;
                    } else {
                        $default_type_atomic = Constant_Type_Resolver::resolve($codebase->classlikes, $param->default_type, $statements_analyzer);
                        $default_type = new Union([$default_type_atomic]);
                    }
                    Template_Standin_Type_Replacer::fill_template_result($param->type, $template_result, $codebase, $statements_analyzer, $default_type, $i, $context->self, $context->calling_method_id ?: $context->calling_function_id, true);
                }
            }
        }
    }
}