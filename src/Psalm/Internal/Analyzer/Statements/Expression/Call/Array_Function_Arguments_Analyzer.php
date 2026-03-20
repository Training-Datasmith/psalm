<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression\Call;

use AssertionError;
use Php_Parser;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Internal\Analyzer\Statements\Expression\Assignment\Array_Assignment_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Assignment_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Expression_Identifier;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Codebase\Internal_Call_Map_Handler;
use Psalm\Internal\Method_Identifier;
use Psalm\Internal\Type\Array_Type;
use Psalm\Internal\Type\Comparator\Type_Comparison_Result;
use Psalm\Internal\Type\Comparator\Union_Type_Comparator;
use Psalm\Internal\Type\Template_Result;
use Psalm\Internal\Type\Template_Standin_Type_Replacer;
use Psalm\Internal\Type\Type_Combiner;
use Psalm\Internal\Type\Type_Expander;
use Psalm\Issue\Argument_Type_Coercion;
use Psalm\Issue\Invalid_Argument;
use Psalm\Issue\Invalid_Scalar_Argument;
use Psalm\Issue\Mixed_Argument_Type_Coercion;
use Psalm\Issue\Possibly_Invalid_Argument;
use Psalm\Issue\Too_Few_Arguments;
use Psalm\Issue\Too_Many_Arguments;
use Psalm\Issue_Buffer;
use Psalm\Node\Expr\Virtual_Array_Dim_Fetch;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Callable;
use Psalm\Type\Atomic\T_Closure;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Atomic\T_Non_Empty_Array;
use Psalm\Type\Union;
use UnexpectedValueException;
use function array_filter;
use function array_pop;
use function array_shift;
use function array_unshift;
use function assert;
use function count;
use function explode;
use function in_array;
use function is_numeric;
use function str_contains;
use function strtolower;
use function substr;
/**
 * @internal
 */
final class Array_Function_Arguments_Analyzer
{
    /**
     * @param   array<int, PhpParser\Node\Arg> $args
     */
    public static function check_arguments_match(Statements_Analyzer $statements_analyzer, Context $context, array $args, string $method_id, bool $check_functions): void
    {
        $closure_index = $method_id === 'array_map' ? 0 : 1;
        $array_arg_types = [];
        foreach ($args as $i => $arg) {
            if ($i === 0 && $method_id === 'array_map') {
                continue;
            }
            if ($i === 1 && in_array($method_id, Arguments_Analyzer::ARRAY_FILTERLIKE, true)) {
                break;
            }
            /**
             * @var TKeyedArray|TArray|null
             */
            $array_arg_type = ($arg_value_type = $statements_analyzer->node_data->get_type($arg->value)) && $arg_value_type->has_array() ? $arg_value_type->get_array() : null;
            if ($array_arg_type instanceof T_Keyed_Array) {
                $array_arg_type = $array_arg_type->get_generic_array_type();
            }
            $array_arg_types[] = $array_arg_type;
        }
        $closure_arg = $args[$closure_index] ?? null;
        $closure_arg_type = null;
        if ($closure_arg) {
            $closure_arg_type = $statements_analyzer->node_data->get_type($closure_arg->value);
        }
        if ($closure_arg && $closure_arg_type) {
            $min_closure_param_count = $max_closure_param_count = count($array_arg_types);
            if ($method_id === 'array_filter') {
                $max_closure_param_count = count($args) > 2 ? 2 : 1;
            } elseif (in_array($method_id, Arguments_Analyzer::ARRAY_FILTERLIKE, true)) {
                $max_closure_param_count = 2;
            }
            $new = [];
            foreach ($closure_arg_type->get_atomic_types() as $closure_type) {
                self::check_closure_type($statements_analyzer, $context, $method_id, $closure_type, $closure_arg, $min_closure_param_count, $max_closure_param_count, $array_arg_types, $check_functions);
                $new[] = $closure_type;
            }
            $statements_analyzer->node_data->set_type($closure_arg->value, $closure_arg_type->get_builder()->set_types($new)->freeze());
        }
    }
    /**
     * @param   list<PhpParser\Node\Arg>          $args
     * @return  false|null
     */
    public static function handle_addition(Statements_Analyzer $statements_analyzer, array $args, Context $context, string $method_id): ?bool
    {
        $array_arg = $args[0]->value;
        $nb_args = count($args);
        $unpacked_args = array_filter($args, static fn(Php_Parser\Node\Arg $arg): bool => $arg->unpack);
        if ($method_id === 'array_push' && !$unpacked_args) {
            for ($i = 1; $i < $nb_args; $i++) {
                $was_inside_assignment = $context->inside_assignment;
                $context->inside_assignment = true;
                if (Expression_Analyzer::analyze($statements_analyzer, $args[$i]->value, $context) === false) {
                    $context->inside_assignment = $was_inside_assignment;
                    return false;
                }
                $context->inside_assignment = $was_inside_assignment;
                $old_node_data = $statements_analyzer->node_data;
                $statements_analyzer->node_data = clone $statements_analyzer->node_data;
                Array_Assignment_Analyzer::analyze($statements_analyzer, new Virtual_Array_Dim_Fetch($args[0]->value, null, $args[$i]->value->get_attributes()), $context, $args[$i]->value, $statements_analyzer->node_data->get_type($args[$i]->value) ?? Type::get_mixed());
                $statements_analyzer->node_data = $old_node_data;
            }
            return null;
        }
        $context->inside_call = true;
        if (Expression_Analyzer::analyze($statements_analyzer, $array_arg, $context) === false) {
            return false;
        }
        for ($i = 1; $i < $nb_args; $i++) {
            if (Expression_Analyzer::analyze($statements_analyzer, $args[$i]->value, $context) === false) {
                return false;
            }
        }
        if (($array_arg_type = $statements_analyzer->node_data->get_type($array_arg)) && $array_arg_type->has_array()) {
            $array_type = $array_arg_type->get_array();
            $objectlike_list = null;
            if ($array_type instanceof T_Keyed_Array) {
                if ($array_type->is_list) {
                    $objectlike_list = $array_type;
                }
            }
            $by_ref_type = new Union([$array_type]);
            foreach ($args as $argument_offset => $arg) {
                if ($argument_offset === 0) {
                    continue;
                }
                if (Expression_Analyzer::analyze($statements_analyzer, $arg->value, $context) === false) {
                    return false;
                }
                if ($method_id === 'array_unshift' && $nb_args === 2 && !$unpacked_args) {
                    $new_offset_type = Type::get_int(false, 0);
                } else {
                    $new_offset_type = Type::get_int();
                }
                if (!($arg_value_type = $statements_analyzer->node_data->get_type($arg->value)) || $arg_value_type->has_mixed()) {
                    $by_ref_type = Type::combine_union_types($by_ref_type, new Union([new T_Array([$new_offset_type, Type::get_mixed()])]));
                } elseif ($arg->unpack) {
                    $arg_value_type = $arg_value_type->get_builder();
                    foreach ($arg_value_type->get_atomic_types() as $arg_value_atomic_type) {
                        if ($arg_value_atomic_type instanceof T_Keyed_Array) {
                            $was_list = $arg_value_atomic_type->is_list;
                            $arg_value_atomic_type = $arg_value_atomic_type->get_generic_array_type();
                            if ($was_list) {
                                if ($arg_value_atomic_type instanceof T_Non_Empty_Array) {
                                    $arg_value_atomic_type = Type::get_non_empty_list_atomic($arg_value_atomic_type->type_params[1]);
                                } else {
                                    $arg_value_atomic_type = Type::get_list_atomic($arg_value_atomic_type->type_params[1]);
                                }
                            }
                            $arg_value_type->add_type($arg_value_atomic_type);
                        }
                    }
                    $arg_value_type = $arg_value_type->freeze();
                    $by_ref_type = Type::combine_union_types($by_ref_type, $arg_value_type);
                } else if ($objectlike_list) {
                    $properties = $objectlike_list->properties;
                    array_unshift($properties, $arg_value_type);
                    $by_ref_type = new Union([$objectlike_list->set_properties($properties)]);
                } elseif ($array_type instanceof T_Array && $array_type->is_empty_array()) {
                    $by_ref_type = new Union([new T_Keyed_Array([$arg_value_type], null, null, true)]);
                } else {
                    $by_ref_type = Type::combine_union_types($by_ref_type, new Union([new T_Non_Empty_Array([$new_offset_type, $arg_value_type])]), null, true);
                }
            }
            Assignment_Analyzer::assign_by_ref_param($statements_analyzer, $array_arg, $by_ref_type, $by_ref_type, $context, false);
        }
        $context->inside_call = false;
        return null;
    }
    /**
     * @param   list<PhpParser\Node\Arg>          $args
     * @return  false|null
     */
    public static function handle_splice(Statements_Analyzer $statements_analyzer, array $args, Context $context): ?bool
    {
        $context->inside_call = true;
        $array_arg = $args[0]->value;
        if (Expression_Analyzer::analyze($statements_analyzer, $array_arg, $context) === false) {
            return false;
        }
        $array_type = null;
        $array_size = null;
        if (($array_arg_type = $statements_analyzer->node_data->get_type($array_arg)) && $array_arg_type->has_array()) {
            $array_type = $array_arg_type->get_array();
            if ($generic_array_type = Array_Type::infer($array_type)) {
                $array_size = $generic_array_type->count;
            }
            if ($array_type instanceof T_Keyed_Array) {
                if ($array_type->is_list && isset($args[3])) {
                    $array_type = Type::get_non_empty_list_atomic($array_type->get_generic_value_type());
                } else {
                    $array_type = $array_type->get_generic_array_type();
                }
            }
            if ($array_type instanceof T_Array && $array_type->type_params[0]->has_int() && !$array_type->type_params[0]->has_string()) {
                if ($array_type instanceof T_Non_Empty_Array && isset($args[3])) {
                    $array_type = Type::get_non_empty_list_atomic($array_type->type_params[1]);
                } else {
                    $array_type = Type::get_list_atomic($array_type->type_params[1]);
                }
            }
        }
        $offset_arg = $args[1]->value;
        if (Expression_Analyzer::analyze($statements_analyzer, $offset_arg, $context) === false) {
            return false;
        }
        $offset_arg_is_zero = false;
        if (($offset_arg_type = $statements_analyzer->node_data->get_type($offset_arg)) && $offset_arg_type->has_literal_value() && $offset_arg_type->is_single_literal()) {
            $offset_literal_value = $offset_arg_type->get_single_literal()->value;
            $offset_arg_is_zero = is_numeric($offset_literal_value) && (int) $offset_literal_value === 0;
        }
        if (!isset($args[2])) {
            if ($offset_arg_is_zero) {
                $array_type = Type::get_empty_array();
                Assignment_Analyzer::assign_by_ref_param($statements_analyzer, $array_arg, $array_type, $array_type, $context, false);
            } elseif ($array_type) {
                Assignment_Analyzer::assign_by_ref_param($statements_analyzer, $array_arg, new Union([$array_type]), new Union([$array_type]), $context, false);
            } else {
                $default_array_type = Type::get_array();
                Assignment_Analyzer::assign_by_ref_param($statements_analyzer, $array_arg, $default_array_type, $default_array_type, $context, false);
            }
            return null;
        }
        $length_arg = $args[2]->value;
        if (Expression_Analyzer::analyze($statements_analyzer, $length_arg, $context) === false) {
            return false;
        }
        $cover_whole_arr = false;
        if ($offset_arg_is_zero && is_numeric($array_size)) {
            if (($length_arg_type = $statements_analyzer->node_data->get_type($length_arg)) && $length_arg_type->has_literal_value()) {
                $length_min = null;
                if ($length_arg_type->is_single_literal()) {
                    $length_literal = $length_arg_type->get_single_literal();
                    if ($length_literal->is_numeric_type()) {
                        $length_min = (int) $length_literal->value;
                    }
                } else {
                    foreach ([...$length_arg_type->get_literal_strings(), ...$length_arg_type->get_literal_ints(), ...$length_arg_type->get_literal_floats()] as $literal) {
                        if ($literal->is_numeric_type() && ($literal_val = (int) $literal->value) && (isset($length_min) && $length_min > $literal_val || !isset($length_min))) {
                            $length_min = $literal_val;
                        }
                    }
                }
                $cover_whole_arr = isset($length_min) && $length_min >= $array_size;
            } elseif ($length_arg_type && $length_arg_type->is_null()) {
                $cover_whole_arr = true;
            }
        }
        if (!isset($args[3])) {
            if ($cover_whole_arr) {
                $array_type = Type::get_empty_array();
                Assignment_Analyzer::assign_by_ref_param($statements_analyzer, $array_arg, $array_type, $array_type, $context, false);
            } elseif ($array_type) {
                Assignment_Analyzer::assign_by_ref_param($statements_analyzer, $array_arg, new Union([$array_type]), new Union([$array_type]), $context, false);
            } else {
                $default_array_type = Type::get_array();
                Assignment_Analyzer::assign_by_ref_param($statements_analyzer, $array_arg, $default_array_type, $default_array_type, $context, false);
            }
            return null;
        }
        $replacement_arg = $args[3]->value;
        if (Expression_Analyzer::analyze($statements_analyzer, $replacement_arg, $context) === false) {
            return false;
        }
        $context->inside_call = false;
        $replacement_arg_type = $statements_analyzer->node_data->get_type($replacement_arg);
        if ($replacement_arg_type && !$replacement_arg_type->has_array() && $replacement_arg_type->has_string() && $replacement_arg_type->is_single()) {
            $replacement_arg_type = new Union([new T_Array([Type::get_int(), $replacement_arg_type])]);
            $statements_analyzer->node_data->set_type($replacement_arg, $replacement_arg_type);
        }
        if ($array_type && $replacement_arg_type && $replacement_arg_type->has_array()) {
            /**
             * @var TArray|TKeyedArray
             */
            $replacement_array_type = $replacement_arg_type->get_array();
            if (($replacement_array_type_generic = Array_Type::infer($replacement_array_type)) && $replacement_array_type_generic->count === 0 && $cover_whole_arr) {
                $empty_array_type = Type::get_empty_array();
                Assignment_Analyzer::assign_by_ref_param($statements_analyzer, $array_arg, $empty_array_type, $empty_array_type, $context, false);
                return null;
            }
            if ($replacement_array_type instanceof T_Keyed_Array) {
                $was_list = $replacement_array_type->is_list;
                $replacement_array_type = $replacement_array_type->get_generic_array_type();
                if ($was_list) {
                    if ($replacement_array_type instanceof T_Non_Empty_Array) {
                        $replacement_array_type = Type::get_non_empty_list_atomic($replacement_array_type->type_params[1]);
                    } else {
                        $replacement_array_type = Type::get_list_atomic($replacement_array_type->type_params[1]);
                    }
                }
            }
            $by_ref_type = Type_Combiner::combine([$array_type, $replacement_array_type]);
            Assignment_Analyzer::assign_by_ref_param($statements_analyzer, $array_arg, $by_ref_type, $by_ref_type, $context, false);
            return null;
        }
        if ($array_type) {
            Assignment_Analyzer::assign_by_ref_param($statements_analyzer, $array_arg, new Union([$array_type]), new Union([$array_type]), $context, false);
        } else {
            $default_array_type = Type::get_array();
            Assignment_Analyzer::assign_by_ref_param($statements_analyzer, $array_arg, $default_array_type, $default_array_type, $context, false);
        }
        return null;
    }
    public static function handle_by_ref_array_adjustment(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Arg $arg, Context $context, bool $is_array_shift): void
    {
        $var_id = Expression_Identifier::get_var_id($arg->value, $statements_analyzer->get_fqcln(), $statements_analyzer);
        if ($var_id) {
            $context->remove_var_from_conflicting_clauses($var_id, null, $statements_analyzer);
            if (isset($context->vars_in_scope[$var_id])) {
                $array_atomic_types = [];
                foreach ($context->vars_in_scope[$var_id]->get_atomic_types() as $array_atomic_type) {
                    if ($array_atomic_type instanceof T_Keyed_Array) {
                        if ($is_array_shift && $array_atomic_type->is_list && !$context->inside_loop) {
                            $array_properties = $array_atomic_type->properties;
                            array_shift($array_properties);
                            if (!$array_properties) {
                                $array_atomic_types[] = $array_atomic_type->fallback_params ? Type::get_list_atomic($array_atomic_type->fallback_params[1]) : Type::get_empty_array_atomic();
                            } else {
                                $array_atomic_types[] = $array_atomic_type->set_properties($array_properties);
                            }
                            continue;
                        }
                        if (!$is_array_shift && $array_atomic_type->is_list && !$array_atomic_type->fallback_params && !$context->inside_loop) {
                            $array_properties = $array_atomic_type->properties;
                            array_pop($array_properties);
                            if (!$array_properties) {
                                $array_atomic_types[] = Type::get_empty_array_atomic();
                            } else {
                                $array_atomic_types[] = $array_atomic_type->set_properties($array_properties);
                            }
                            continue;
                        }
                        $array_atomic_type = $array_atomic_type->is_list ? Type::get_list_atomic($array_atomic_type->get_generic_value_type()) : $array_atomic_type->get_generic_array_type();
                    }
                    if ($array_atomic_type instanceof T_Non_Empty_Array) {
                        if (!$context->inside_loop && $array_atomic_type->count !== null) {
                            if ($array_atomic_type->count === 1) {
                                $array_atomic_type = new T_Array([Type::get_never(), Type::get_never()]);
                            } else {
                                $array_atomic_type = $array_atomic_type->set_count($array_atomic_type->count - 1);
                            }
                        } else {
                            $array_atomic_type = new T_Array($array_atomic_type->type_params);
                        }
                        $array_atomic_types[] = $array_atomic_type;
                    } elseif ($array_atomic_type instanceof T_Keyed_Array && $array_atomic_type->is_list) {
                        if (!$context->inside_loop && ($prop_count = $array_atomic_type->get_max_count()) && $prop_count === $array_atomic_type->get_min_count()) {
                            if ($prop_count === 1) {
                                $array_atomic_type = new T_Array([Type::get_never(), Type::get_never()]);
                            } else {
                                $properties = $array_atomic_type->properties;
                                unset($properties[$prop_count - 1]);
                                assert($properties !== []);
                                $array_atomic_type = $array_atomic_type->set_properties($properties);
                            }
                        } else {
                            $array_atomic_type = Type::get_list_atomic($array_atomic_type->get_generic_value_type());
                        }
                        $array_atomic_types[] = $array_atomic_type;
                    } else {
                        $array_atomic_types[] = $array_atomic_type;
                    }
                }
                if (!$array_atomic_types) {
                    throw new AssertionError("We must have some types here!");
                }
                $array_type = new Union($array_atomic_types);
                $context->remove_descendents($var_id, $array_type);
                $context->vars_in_scope[$var_id] = $array_type;
            }
        }
    }
    /**
     * @param  (TArray|null)[] $array_arg_types
     */
    private static function check_closure_type(Statements_Analyzer $statements_analyzer, Context $context, string $method_id, Atomic &$closure_type, Php_Parser\Node\Arg $closure_arg, int $min_closure_param_count, int $max_closure_param_count, array $array_arg_types, bool $check_functions): void
    {
        $codebase = $statements_analyzer->get_codebase();
        if (!$closure_type instanceof T_Closure) {
            if ($method_id === 'array_map') {
                return;
            }
            if (!$closure_arg->value instanceof Php_Parser\Node\Scalar\String_ && !$closure_arg->value instanceof Php_Parser\Node\Expr\Array_ && !$closure_arg->value instanceof Php_Parser\Node\Expr\Binary_Op\Concat) {
                return;
            }
            $function_ids = Call_Analyzer::get_function_ids_from_callable_arg($statements_analyzer, $closure_arg->value);
            $closure_types = [];
            foreach ($function_ids as $function_id) {
                $function_id = strtolower($function_id);
                if (str_contains($function_id, '::')) {
                    if ($function_id[0] === '$') {
                        $function_id = substr($function_id, 1);
                    }
                    $function_id_parts = explode('&', $function_id);
                    foreach ($function_id_parts as $function_id_part) {
                        [$callable_fq_class_name, $method_name] = explode('::', $function_id_part);
                        switch ($callable_fq_class_name) {
                            case 'self':
                            case 'static':
                            case 'parent':
                                $container_class = $statements_analyzer->get_fqcln();
                                if ($callable_fq_class_name === 'parent') {
                                    $container_class = $statements_analyzer->get_parent_fqcln();
                                }
                                if (!$container_class) {
                                    continue 2;
                                }
                                $callable_fq_class_name = $container_class;
                        }
                        if (!$codebase->class_or_interface_exists($callable_fq_class_name)) {
                            return;
                        }
                        $function_id_part = new Method_Identifier($callable_fq_class_name, strtolower($method_name));
                        try {
                            $method_storage = $codebase->methods->get_storage($function_id_part);
                        } catch (UnexpectedValueException) {
                            // the method may not exist, but we're suppressing that issue
                            continue;
                        }
                        $closure_types[] = new T_Closure('Closure', $method_storage->params, $method_storage->return_type ?: Type::get_mixed());
                    }
                } else {
                    if (!$check_functions) {
                        continue;
                    }
                    if (!$codebase->functions->function_exists($statements_analyzer, $function_id)) {
                        continue;
                    }
                    $function_storage = $codebase->functions->get_storage($statements_analyzer, $function_id);
                    if (Internal_Call_Map_Handler::in_call_map($function_id)) {
                        $callmap_callables = Internal_Call_Map_Handler::get_callables_from_call_map($function_id);
                        if ($callmap_callables === null) {
                            throw new UnexpectedValueException('This should not happen');
                        }
                        $passing_callmap_callables = [];
                        foreach ($callmap_callables as $callmap_callable) {
                            $required_param_count = 0;
                            assert($callmap_callable->params !== null);
                            foreach ($callmap_callable->params as $i => $param) {
                                if (!$param->is_optional && !$param->is_variadic) {
                                    $required_param_count = $i + 1;
                                }
                            }
                            if ($required_param_count <= $max_closure_param_count) {
                                $passing_callmap_callables[] = $callmap_callable;
                            }
                        }
                        if ($passing_callmap_callables) {
                            foreach ($passing_callmap_callables as $passing_callmap_callable) {
                                $closure_types[] = $passing_callmap_callable;
                            }
                        } else {
                            $closure_types[] = $callmap_callables[0];
                        }
                    } else {
                        $closure_types[] = new T_Closure('Closure', $function_storage->params, $function_storage->return_type ?: Type::get_mixed());
                    }
                }
            }
        } else {
            $closure_types = [&$closure_type];
        }
        foreach ($closure_types as &$closure_type) {
            if ($closure_type->params === null) {
                continue;
            }
            self::check_closure_type_args($statements_analyzer, $context, $method_id, $closure_type, $closure_arg, $min_closure_param_count, $max_closure_param_count, $array_arg_types);
        }
        unset($closure_type);
    }
    /**
     * @param  TClosure|TCallable $closure_type
     * @param  (TArray|null)[] $array_arg_types
     */
    private static function check_closure_type_args(Statements_Analyzer $statements_analyzer, Context $context, string $method_id, Atomic &$closure_type, Php_Parser\Node\Arg $closure_arg, int $min_closure_param_count, int $max_closure_param_count, array $array_arg_types): void
    {
        $codebase = $statements_analyzer->get_codebase();
        $closure_params = $closure_type->params;
        if ($closure_params === null) {
            throw new UnexpectedValueException('Closure params should not be null here');
        }
        $required_param_count = 0;
        foreach ($closure_params as $i => $param) {
            if (!$param->is_optional && !$param->is_variadic) {
                $required_param_count = $i + 1;
            }
        }
        if (count($closure_params) < $min_closure_param_count) {
            $argument_text = $min_closure_param_count === 1 ? 'one argument' : $min_closure_param_count . ' arguments';
            Issue_Buffer::maybe_add(new Too_Many_Arguments('The callable passed to ' . $method_id . ' will be called with ' . $argument_text . ', expecting ' . $required_param_count, new Code_Location($statements_analyzer->get_source(), $closure_arg), $method_id), $statements_analyzer->get_suppressed_issues());
            return;
        }
        if ($required_param_count > $max_closure_param_count) {
            $argument_text = $max_closure_param_count === 1 ? 'one argument' : $max_closure_param_count . ' arguments';
            Issue_Buffer::maybe_add(new Too_Few_Arguments('The callable passed to ' . $method_id . ' will be called with ' . $argument_text . ', expecting ' . $required_param_count, new Code_Location($statements_analyzer->get_source(), $closure_arg), $method_id), $statements_analyzer->get_suppressed_issues());
            return;
        }
        // abandon attempt to validate closure params if we have an extra arg for ARRAY_FILTER
        if ($method_id === 'array_filter' && $max_closure_param_count > 1) {
            return;
        }
        foreach ($closure_params as $i => $closure_param) {
            if (!isset($array_arg_types[$i])) {
                continue;
            }
            $array_arg_type = $array_arg_types[$i];
            $input_type = $array_arg_type->type_params[1];
            if ($input_type->has_mixed()) {
                continue;
            }
            $closure_param_type = $closure_param->type;
            if (!$closure_param_type) {
                continue;
            }
            if ($method_id === 'array_map' && $i === 0 && $closure_type->return_type && $closure_param_type->has_template()) {
                $template_result = new Template_Result([], []);
                foreach ($closure_param_type->get_template_types() as $template_type) {
                    $template_result->template_types[$template_type->param_name] = [$template_type->defining_class => $template_type->as];
                }
                $closure_param_type = Template_Standin_Type_Replacer::replace($closure_param_type, $template_result, $codebase, $statements_analyzer, $input_type, $i, $context->self, $context->calling_method_id ?: $context->calling_function_id);
                $closure_type = $closure_type->replace_template_types_with_arg_types($template_result, $codebase);
            }
            $closure_param_type = Type_Expander::expand_union($codebase, $closure_param_type, $context->self, null, $statements_analyzer->get_parent_fqcln());
            $union_comparison_results = new Type_Comparison_Result();
            $type_match_found = Union_Type_Comparator::is_contained_by($codebase, $input_type, $closure_param_type, $input_type->ignore_nullable_issues, $input_type->ignore_falsable_issues, $union_comparison_results);
            if ($union_comparison_results->type_coerced) {
                if ($union_comparison_results->type_coerced_from_mixed) {
                    Issue_Buffer::maybe_add(new Mixed_Argument_Type_Coercion('Parameter ' . ($i + 1) . ' of closure passed to function ' . $method_id . ' expects ' . $closure_param_type->get_id() . ', but parent type ' . $input_type->get_id() . ' provided', new Code_Location($statements_analyzer->get_source(), $closure_arg), $method_id), $statements_analyzer->get_suppressed_issues());
                } else {
                    Issue_Buffer::maybe_add(new Argument_Type_Coercion('Parameter ' . ($i + 1) . ' of closure passed to function ' . $method_id . ' expects ' . $closure_param_type->get_id() . ', but parent type ' . $input_type->get_id() . ' provided', new Code_Location($statements_analyzer->get_source(), $closure_arg), $method_id), $statements_analyzer->get_suppressed_issues());
                }
            }
            if (!$union_comparison_results->type_coerced && !$type_match_found) {
                $types_can_be_identical = Union_Type_Comparator::can_expression_types_be_identical($codebase, $input_type, $closure_param_type);
                if ($union_comparison_results->scalar_type_match_found) {
                    Issue_Buffer::maybe_add(new Invalid_Scalar_Argument('Parameter ' . ($i + 1) . ' of closure passed to function ' . $method_id . ' expects ' . $closure_param_type->get_id() . ', but ' . $input_type->get_id() . ' provided', new Code_Location($statements_analyzer->get_source(), $closure_arg), $method_id), $statements_analyzer->get_suppressed_issues());
                } elseif ($types_can_be_identical) {
                    Issue_Buffer::maybe_add(new Possibly_Invalid_Argument('Parameter ' . ($i + 1) . ' of closure passed to function ' . $method_id . ' expects ' . $closure_param_type->get_id() . ', but possibly different type ' . $input_type->get_id() . ' provided', new Code_Location($statements_analyzer->get_source(), $closure_arg), $method_id), $statements_analyzer->get_suppressed_issues());
                } else {
                    Issue_Buffer::maybe_add(new Invalid_Argument('Parameter ' . ($i + 1) . ' of closure passed to function ' . $method_id . ' expects ' . $closure_param_type->get_id() . ', but ' . $input_type->get_id() . ' provided', new Code_Location($statements_analyzer->get_source(), $closure_arg), $method_id), $statements_analyzer->get_suppressed_issues());
                }
            }
        }
    }
}