<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression\Call;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Internal\Analyzer\Class_Like_Analyzer;
use Psalm\Internal\Analyzer\Class_Like_Name_Options;
use Psalm\Internal\Analyzer\Function_Like_Analyzer;
use Psalm\Internal\Analyzer\Method_Analyzer;
use Psalm\Internal\Analyzer\Statements\Block\Foreach_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Cast_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Expression_Identifier;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Analyzer\Trait_Analyzer;
use Psalm\Internal\Codebase\Constant_Type_Resolver;
use Psalm\Internal\Codebase\Taint_Flow_Graph;
use Psalm\Internal\Codebase\Variable_Use_Graph;
use Psalm\Internal\Data_Flow\Data_Flow_Node;
use Psalm\Internal\Data_Flow\Taint_Source;
use Psalm\Internal\Method_Identifier;
use Psalm\Internal\Type\Comparator\Callable_Type_Comparator;
use Psalm\Internal\Type\Comparator\Type_Comparison_Result;
use Psalm\Internal\Type\Comparator\Union_Type_Comparator;
use Psalm\Internal\Type\Template_Bound;
use Psalm\Internal\Type\Template_Result;
use Psalm\Internal\Type\Template_Standin_Type_Replacer;
use Psalm\Internal\Type\Type_Expander;
use Psalm\Issue\Argument_Type_Coercion;
use Psalm\Issue\Deprecated_Constant;
use Psalm\Issue\Implicit_To_String_Cast;
use Psalm\Issue\Invalid_Argument;
use Psalm\Issue\Invalid_Literal_Argument;
use Psalm\Issue\Invalid_Scalar_Argument;
use Psalm\Issue\Mixed_Argument;
use Psalm\Issue\Mixed_Argument_Type_Coercion;
use Psalm\Issue\Named_Argument_Not_Allowed;
use Psalm\Issue\No_Value;
use Psalm\Issue\Null_Argument;
use Psalm\Issue\Parent_Not_Found;
use Psalm\Issue\Possibly_False_Argument;
use Psalm\Issue\Possibly_Invalid_Argument;
use Psalm\Issue\Possibly_Null_Argument;
use Psalm\Issue_Buffer;
use Psalm\Node\Virtual_Arg;
use Psalm\Plugin\Event_Handler\Event\Add_Remove_Taints_Event;
use Psalm\Storage\Function_Like_Parameter;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Callable;
use Psalm\Type\Atomic\T_Class_String;
use Psalm\Type\Atomic\T_Class_String_Map;
use Psalm\Type\Atomic\T_Generic_Object;
use Psalm\Type\Atomic\T_Iterable;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Atomic\T_Literal_String;
use Psalm\Type\Atomic\T_Mixed;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Union;
use UnexpectedValueException;
use function array_diff;
use function array_filter;
use function count;
use function explode;
use function implode;
use function in_array;
use function ord;
use function preg_split;
use function reset;
use function str_contains;
use function str_starts_with;
use function strpos;
use function strtolower;
use function substr;
use function substr_count;
use const DIRECTORY_SEPARATOR;
use const PREG_SPLIT_NO_EMPTY;
/**
 * @internal
 */
final class Argument_Analyzer
{
    // callable $this
    // some PHP-internal functions (e.g. array_filter) will call the callback within the current context
    // unlike user-defined functions which call the callback in their context
    // however this doesn't apply to all
    // e.g. header_register_callback will not throw an error immediately like user-land functions
    // however error log "Could not call the sapi_header_callback" if it's not public
    // this is NOT a complete list, but just what was easily available and to be extended
    private const PHP_NATIVE_NON_PUBLIC_CB = [...Arguments_Analyzer::ARRAY_FILTERLIKE, 'array_diff_uassoc', 'array_diff_ukey', 'array_intersect_uassoc', 'array_intersect_ukey', 'array_map', 'array_reduce', 'array_udiff', 'array_udiff_assoc', 'array_udiff_uassoc', 'array_uintersect', 'array_uintersect_assoc', 'array_uintersect_uassoc', 'array_walk', 'array_walk_recursive', 'preg_replace_callback', 'preg_replace_callback_array', 'call_user_func', 'call_user_func_array', 'forward_static_call', 'forward_static_call_array', 'is_callable', 'ob_start', 'register_shutdown_function', 'register_tick_function', 'session_set_save_handler', 'set_error_handler', 'set_exception_handler', 'spl_autoload_register', 'spl_autoload_unregister', 'uasort', 'uksort', 'usort'];
    /**
     * @param  array<string, array<string, Union>> $class_generic_params
     * @return false|null
     */
    public static function check_argument_matches(Statements_Analyzer $statements_analyzer, ?string $cased_method_id, ?Method_Identifier $method_id, ?string $self_fq_class_name, ?string $static_fq_class_name, Code_Location $function_call_location, ?Function_Like_Parameter $function_param, int $argument_offset, int $unpacked_argument_offset, bool $allow_named_args, Php_Parser\Node\Arg $arg, ?Union $arg_value_type, Context $context, array $class_generic_params, ?Template_Result $template_result, bool $specialize_taint, bool $in_call_map): ?bool
    {
        $codebase = $statements_analyzer->get_codebase();
        if (!$arg_value_type) {
            if ($function_param && !$function_param->by_ref) {
                if (!$context->collect_initializations && !$context->collect_mutations && $statements_analyzer->get_file_path() === $statements_analyzer->get_root_file_path() && (!($parent_source = $statements_analyzer->get_source()) instanceof Function_Like_Analyzer || !$parent_source->get_source() instanceof Trait_Analyzer)) {
                    $codebase->analyzer->increment_mixed_count($statements_analyzer->get_file_path());
                }
                $param_type = $function_param->type;
                if ($function_param->is_variadic && $param_type && $param_type->has_array()) {
                    $array_type = $param_type->get_array();
                    if ($array_type instanceof T_Keyed_Array && $array_type->is_list) {
                        $param_type = $array_type->get_generic_value_type();
                    } elseif ($array_type instanceof T_Array) {
                        $param_type = $array_type->type_params[1];
                    }
                }
                if ($param_type && !$param_type->has_mixed()) {
                    Issue_Buffer::maybe_add(new Mixed_Argument('Argument ' . ($argument_offset + 1) . ' of ' . $cased_method_id . ' cannot be mixed, expecting ' . $param_type, new Code_Location($statements_analyzer->get_source(), $arg->value), $cased_method_id), $statements_analyzer->get_suppressed_issues());
                }
            }
            return null;
        }
        if (!$function_param) {
            return null;
        }
        if ($function_param->expect_variable && $arg_value_type->is_single_string_literal() && !$arg->value instanceof Php_Parser\Node\Scalar\Magic_Const && !$arg->value instanceof Php_Parser\Node\Expr\Const_Fetch && !$arg->value instanceof Php_Parser\Node\Expr\Class_Const_Fetch) {
            $values = preg_split('//u', $arg_value_type->get_single_string_literal()->value, -1, PREG_SPLIT_NO_EMPTY);
            if ($values !== false) {
                $prev_ord = 0;
                $gt_count = 0;
                foreach ($values as $value) {
                    $ord = ord($value);
                    if ($ord > $prev_ord) {
                        $gt_count++;
                    }
                    $prev_ord = $ord;
                }
                if (substr_count($arg_value_type->get_single_string_literal()->value, DIRECTORY_SEPARATOR) <= 2 && (count($values) < 12 || $gt_count / count($values) < 0.8)) {
                    Issue_Buffer::maybe_add(new Invalid_Literal_Argument('Argument ' . ($argument_offset + 1) . ' of ' . $cased_method_id . ' expects a non-literal value, but ' . $arg_value_type->get_id() . ' provided', new Code_Location($statements_analyzer->get_source(), $arg->value), $cased_method_id), $statements_analyzer->get_suppressed_issues());
                }
            }
        }
        if (self::check_function_like_type_matches($statements_analyzer, $codebase, $cased_method_id, $method_id, $self_fq_class_name, $static_fq_class_name, $function_call_location, $function_param, $allow_named_args, $arg_value_type, $argument_offset, $unpacked_argument_offset, $arg, $context, $class_generic_params, $template_result, $specialize_taint, $in_call_map) === false) {
            return false;
        }
        return null;
    }
    /**
     * @param  array<string, array<string, Union>> $class_generic_params
     * @return false|null
     */
    private static function check_function_like_type_matches(Statements_Analyzer $statements_analyzer, Codebase $codebase, ?string $cased_method_id, ?Method_Identifier $method_id, ?string $self_fq_class_name, ?string $static_fq_class_name, Code_Location $function_call_location, Function_Like_Parameter $function_param, bool $allow_named_args, Union $arg_value_type, int $argument_offset, int $unpacked_argument_offset, Php_Parser\Node\Arg $arg, Context $context, ?array $class_generic_params, ?Template_Result $template_result, bool $specialize_taint, bool $in_call_map): ?bool
    {
        if (!$function_param->type) {
            if (!$codebase->infer_types_from_usage && !$statements_analyzer->data_flow_graph) {
                return null;
            }
            $param_type = Type::get_mixed();
        } else {
            $param_type = $function_param->type;
        }
        $bindable_template_params = [];
        if ($template_result) {
            $bindable_template_params = $param_type->get_template_types();
        }
        $parent_class = null;
        $classlike_storage = null;
        $static_classlike_storage = null;
        if ($self_fq_class_name) {
            $classlike_storage = $codebase->classlike_storage_provider->get($self_fq_class_name);
            $parent_class = $classlike_storage->parent_class;
            $static_classlike_storage = $classlike_storage;
            if ($static_fq_class_name && $static_fq_class_name !== $self_fq_class_name) {
                $static_classlike_storage = $codebase->classlike_storage_provider->get($static_fq_class_name);
            }
        }
        $param_type = Type_Expander::expand_union($codebase, $param_type, $classlike_storage->name ?? null, $static_classlike_storage->name ?? null, $parent_class, true, false, $static_classlike_storage->final ?? false, true);
        if ($class_generic_params) {
            // here we're replacing the param types and arg types with the bound
            // class template params.
            //
            // For example, if we're operating on a class Foo with params TKey and TValue,
            // and we're calling a method "add(TKey $key, TValue $value)" on an instance
            // of that class where we know that TKey is int and TValue is string, then we
            // want to substitute the expected parameters so it's as if we were actually
            // calling "add(int $key, string $value)"
            $readonly_template_result = new Template_Result($class_generic_params, []);
            // This flag ensures that the template results will never be written to
            // It also supersedes the `$add_lower_bounds` flag so that closure params
            // don’t get overwritten
            $readonly_template_result->readonly = true;
            $param_type = Template_Standin_Type_Replacer::replace($param_type, $readonly_template_result, $codebase, $statements_analyzer, $arg_value_type, $argument_offset, $context->self, $context->calling_function_id ?: $context->calling_method_id);
            $arg_value_type = Template_Standin_Type_Replacer::replace($arg_value_type, $readonly_template_result, $codebase, $statements_analyzer, $arg_value_type, $argument_offset, $context->self, $context->calling_function_id ?: $context->calling_method_id);
        }
        if ($template_result && $template_result->template_types) {
            $arg_type_param = $arg_value_type;
            if ($arg->unpack) {
                $arg_type_param = null;
                foreach ($arg_value_type->get_atomic_types() as $arg_atomic_type) {
                    if ($arg_atomic_type instanceof T_Array || $arg_atomic_type instanceof T_Keyed_Array) {
                        if ($arg_atomic_type instanceof T_Keyed_Array) {
                            $arg_type_param = $arg_atomic_type->get_generic_value_type();
                        } else {
                            $arg_type_param = $arg_atomic_type->type_params[1];
                        }
                    } elseif ($arg_atomic_type instanceof T_Iterable) {
                        $arg_type_param = $arg_atomic_type->type_params[1];
                    } elseif ($arg_atomic_type instanceof T_Named_Object) {
                        Foreach_Analyzer::get_key_value_params_for_traversable_object($arg_atomic_type, $codebase, $key_type, $arg_type_param);
                    }
                }
                if (!$arg_type_param) {
                    $arg_type_param = new Union([new T_Mixed()], ['parent_nodes' => $arg_value_type->parent_nodes]);
                }
            }
            $param_type = Template_Standin_Type_Replacer::replace($param_type, $template_result, $codebase, $statements_analyzer, $arg_type_param, $argument_offset, !$statements_analyzer->is_static() && (!$method_id || $method_id->method_name !== '__construct') ? $context->self : null, $context->calling_method_id ?: $context->calling_function_id);
            foreach ($bindable_template_params as $template_type) {
                if (!isset($template_result->lower_bounds[$template_type->param_name][$template_type->defining_class])) {
                    if (isset($template_result->upper_bounds[$template_type->param_name][$template_type->defining_class])) {
                        $template_result->lower_bounds[$template_type->param_name][$template_type->defining_class] = [new Template_Bound($template_result->upper_bounds[$template_type->param_name][$template_type->defining_class]->type)];
                    } else {
                        $template_result->lower_bounds[$template_type->param_name][$template_type->defining_class] = [new Template_Bound($template_type->as)];
                    }
                }
            }
            $param_type = Type_Expander::expand_union($codebase, $param_type, $classlike_storage->name ?? null, $static_classlike_storage->name ?? null, $parent_class, true, false, $static_classlike_storage->final ?? false, true);
        }
        $fleshed_out_signature_type = $function_param->signature_type ? Type_Expander::expand_union($codebase, $function_param->signature_type, $classlike_storage->name ?? null, $static_classlike_storage->name ?? null, $parent_class) : null;
        $unpacked_atomic_array = null;
        if ($arg->unpack) {
            if ($arg_value_type->has_mixed()) {
                if (!$context->collect_initializations && !$context->collect_mutations && $statements_analyzer->get_file_path() === $statements_analyzer->get_root_file_path() && (!($parent_source = $statements_analyzer->get_source()) instanceof Function_Like_Analyzer || !$parent_source->get_source() instanceof Trait_Analyzer)) {
                    $codebase->analyzer->increment_mixed_count($statements_analyzer->get_file_path());
                }
                Issue_Buffer::maybe_add(new Mixed_Argument('Argument ' . ($argument_offset + 1) . ' of ' . $cased_method_id . ' cannot unpack ' . $arg_value_type->get_id() . ', expecting iterable', new Code_Location($statements_analyzer->get_source(), $arg->value), $cased_method_id), $statements_analyzer->get_suppressed_issues());
                if ($cased_method_id) {
                    $arg_location = new Code_Location($statements_analyzer->get_source(), $arg->value);
                    self::process_taintedness($statements_analyzer, $cased_method_id, $method_id, $argument_offset, $arg_location, $function_call_location, $function_param, $arg_value_type, $arg->value, $context, $specialize_taint);
                }
                return null;
            }
            if ($arg_value_type->has_array()) {
                $unpacked_atomic_array = $arg_value_type->get_array();
                $arg_key_allowed = true;
                if ($unpacked_atomic_array instanceof T_Keyed_Array) {
                    if (!$allow_named_args && !$unpacked_atomic_array->get_generic_key_type()->is_int()) {
                        $arg_key_allowed = false;
                    }
                    if ($function_param->is_variadic) {
                        $arg_value_type = $unpacked_atomic_array->get_generic_value_type();
                    } elseif ($codebase->analysis_php_version_id >= 80000 && $allow_named_args && isset($unpacked_atomic_array->properties[$function_param->name])) {
                        $arg_value_type = $unpacked_atomic_array->properties[$function_param->name];
                    } elseif ($unpacked_atomic_array->is_list && isset($unpacked_atomic_array->properties[$unpacked_argument_offset])) {
                        $arg_value_type = $unpacked_atomic_array->properties[$unpacked_argument_offset];
                    } elseif ($unpacked_atomic_array->fallback_params) {
                        $arg_value_type = $unpacked_atomic_array->fallback_params[1];
                    } elseif ($function_param->is_optional && $function_param->default_type) {
                        if ($function_param->default_type instanceof Union) {
                            $arg_value_type = $function_param->default_type;
                        } else {
                            $arg_value_type_atomic = Constant_Type_Resolver::resolve($codebase->classlikes, $function_param->default_type, $statements_analyzer);
                            $arg_value_type = new Union([$arg_value_type_atomic]);
                        }
                    } else {
                        $arg_value_type = Type::get_mixed();
                    }
                } elseif ($unpacked_atomic_array instanceof T_Class_String_Map) {
                    $arg_value_type = Type::get_mixed();
                } else {
                    if (!$allow_named_args && !$unpacked_atomic_array->type_params[0]->is_int()) {
                        $arg_key_allowed = false;
                    }
                    $arg_value_type = $unpacked_atomic_array->type_params[1];
                }
                if (!$arg_key_allowed) {
                    Issue_Buffer::maybe_add(new Named_Argument_Not_Allowed('Method ' . $cased_method_id . ' called with named unpacked array ' . $unpacked_atomic_array->get_id() . ' (array with string keys)', new Code_Location($statements_analyzer->get_source(), $arg->value), $cased_method_id), $statements_analyzer->get_suppressed_issues());
                }
            } else {
                $non_iterable = false;
                $invalid_key = false;
                $invalid_string_key = false;
                $possibly_matches = false;
                foreach ($arg_value_type->get_atomic_types() as $atomic_type) {
                    if (!$atomic_type->is_iterable($codebase)) {
                        $non_iterable = true;
                    } else {
                        $key_type = $codebase->get_key_value_params_for_traversable_object($atomic_type)[0];
                        if (!Union_Type_Comparator::is_contained_by($codebase, $key_type, Type::get_array_key())) {
                            $invalid_key = true;
                            continue;
                        }
                        if (($codebase->analysis_php_version_id < 80000 || !$allow_named_args) && !$key_type->is_int()) {
                            $invalid_string_key = true;
                            continue;
                        }
                        $possibly_matches = true;
                    }
                }
                $issue_type = $possibly_matches ? Possibly_Invalid_Argument::class : Invalid_Argument::class;
                if ($non_iterable) {
                    Issue_Buffer::maybe_add(new $issue_type('Tried to unpack non-iterable ' . $arg_value_type->get_id(), new Code_Location($statements_analyzer->get_source(), $arg->value), $cased_method_id), $statements_analyzer->get_suppressed_issues());
                }
                if ($invalid_key) {
                    Issue_Buffer::maybe_add(new $issue_type('Method ' . $cased_method_id . ' called with unpacked iterable ' . $arg_value_type->get_id() . ' with invalid key (must be ' . ($codebase->analysis_php_version_id < 80000 ? 'int' : 'int|string') . ')', new Code_Location($statements_analyzer->get_source(), $arg->value), $cased_method_id), $statements_analyzer->get_suppressed_issues());
                }
                if ($invalid_string_key) {
                    if ($codebase->analysis_php_version_id < 80000) {
                        Issue_Buffer::maybe_add(new $issue_type('String keys not supported in unpacked arguments', new Code_Location($statements_analyzer->get_source(), $arg->value), $cased_method_id), $statements_analyzer->get_suppressed_issues());
                    } else {
                        Issue_Buffer::maybe_add(new Named_Argument_Not_Allowed('Method ' . $cased_method_id . ' called with named unpacked iterable ' . $arg_value_type->get_id() . ' (iterable with string keys)', new Code_Location($statements_analyzer->get_source(), $arg->value), $cased_method_id), $statements_analyzer->get_suppressed_issues());
                    }
                }
                return null;
            }
        } else if (!$allow_named_args && $arg->name !== null) {
            Issue_Buffer::maybe_add(new Named_Argument_Not_Allowed('Method ' . $cased_method_id . ' called with named argument ' . $arg->name->name, new Code_Location($statements_analyzer->get_source(), $arg->value), $cased_method_id), $statements_analyzer->get_suppressed_issues());
        }
        // bypass verifying argument types when collecting initialisations,
        // because the argument locations are not reliable (file names normally differ)
        // See https://github.com/vimeo/psalm/issues/5662
        if ($arg instanceof Virtual_Arg && $context->collect_initializations) {
            return null;
        }
        if (self::verify_type($statements_analyzer, $arg_value_type, $param_type, $fleshed_out_signature_type, $cased_method_id, $method_id, $argument_offset, new Code_Location($statements_analyzer->get_source(), $arg->value), $arg->value, $context, $function_param, $arg->unpack, $unpacked_atomic_array, $specialize_taint, $in_call_map, $function_call_location) === false) {
            return false;
        }
        return null;
    }
    /**
     * @param TKeyedArray|TArray|TClassStringMap|null $unpacked_atomic_array
     * @return  null|false
     */
    public static function verify_type(Statements_Analyzer $statements_analyzer, Union $input_type, Union $param_type, ?Union $signature_param_type, ?string $cased_method_id, ?Method_Identifier $method_id, int $argument_offset, Code_Location $arg_location, Php_Parser\Node\Expr $input_expr, Context $context, Function_Like_Parameter $function_param, bool $unpack, ?Atomic $unpacked_atomic_array, bool $specialize_taint, bool $in_call_map, Code_Location $function_call_location): ?bool
    {
        $codebase = $statements_analyzer->get_codebase();
        if ($param_type->has_mixed()) {
            if ($codebase->infer_types_from_usage && !$input_type->has_mixed() && !$param_type->from_docblock && !$param_type->had_template && $method_id && !str_starts_with($method_id->method_name, '__')) {
                $declaring_method_id = $codebase->methods->get_declaring_method_id($method_id);
                if ($declaring_method_id) {
                    $id_lc = strtolower((string) $declaring_method_id);
                    $codebase->analyzer->possible_method_param_types[$id_lc][$argument_offset] = Type::combine_union_types($codebase->analyzer->possible_method_param_types[$id_lc][$argument_offset] ?? null, $input_type, $codebase);
                }
            }
            if ($cased_method_id) {
                self::process_taintedness($statements_analyzer, $cased_method_id, $method_id, $argument_offset, $arg_location, $function_call_location, $function_param, $input_type, $input_expr, $context, $specialize_taint);
            }
            return null;
        }
        $method_identifier = $cased_method_id ? ' of ' . $cased_method_id : '';
        if ($input_type->has_mixed()) {
            if (!$context->collect_initializations && !$context->collect_mutations && $statements_analyzer->get_file_path() === $statements_analyzer->get_root_file_path() && (!($parent_source = $statements_analyzer->get_source()) instanceof Function_Like_Analyzer || !$parent_source->get_source() instanceof Trait_Analyzer)) {
                $codebase->analyzer->increment_mixed_count($statements_analyzer->get_file_path());
            }
            $origin_locations = [];
            if ($statements_analyzer->data_flow_graph instanceof Variable_Use_Graph) {
                foreach ($input_type->parent_nodes as $parent_node) {
                    $origin_locations = [...$origin_locations, ...$statements_analyzer->data_flow_graph->get_origin_locations($parent_node)];
                }
            }
            $origin_location = count($origin_locations) === 1 ? reset($origin_locations) : null;
            if ($origin_location && $origin_location->get_hash() === $arg_location->get_hash()) {
                $origin_location = null;
            }
            Issue_Buffer::maybe_add(new Mixed_Argument('Argument ' . ($argument_offset + 1) . $method_identifier . ' cannot be ' . $input_type->get_id() . ', expecting ' . $param_type, $arg_location, $cased_method_id, $origin_location), $statements_analyzer->get_suppressed_issues());
            if ($input_type->is_mixed()) {
                if (!$function_param->by_ref && !($function_param->is_variadic xor $unpack) && $cased_method_id !== 'echo' && $cased_method_id !== 'print' && (!$in_call_map || $context->strict_types)) {
                    self::coerce_value_after_gatekeeper_argument($statements_analyzer, $input_type, false, $input_expr, $param_type, $signature_param_type, $context, $unpack, $unpacked_atomic_array);
                }
            }
            if ($cased_method_id) {
                self::process_taintedness($statements_analyzer, $cased_method_id, $method_id, $argument_offset, $arg_location, $function_call_location, $function_param, $input_type, $input_expr, $context, $specialize_taint);
            }
            if ($input_type->is_mixed()) {
                return null;
            }
        }
        if ($input_type->is_never()) {
            if (!Issue_Buffer::accepts(new No_Value('All possible types for this argument were invalidated - This may be dead code', $arg_location), $statements_analyzer->get_suppressed_issues())) {
                // if the error is suppressed, do not treat it as exited anymore
                $context->has_returned = false;
            }
            return null;
        }
        if (!$context->collect_initializations && !$context->collect_mutations && $statements_analyzer->get_file_path() === $statements_analyzer->get_root_file_path() && (!($parent_source = $statements_analyzer->get_source()) instanceof Function_Like_Analyzer || !$parent_source->get_source() instanceof Trait_Analyzer)) {
            $codebase->analyzer->increment_non_mixed_count($statements_analyzer->get_file_path());
        }
        if ($function_param->by_ref || $function_param->is_optional) {
            //if the param is optional or a ref, we'll allow the input to be possibly_undefined
            $param_type = $param_type->set_possibly_undefined(true);
        }
        if ($param_type->has_callable_type() && $param_type->is_single()) {
            // we do this replacement early because later we don't have access to the
            // $statements_analyzer, which is necessary to understand string function names
            $input_type = $input_type->get_builder();
            foreach ($input_type->get_atomic_types() as $key => $atomic_type) {
                $container_callable_type = $param_type->get_single_atomic();
                $container_callable_type = $container_callable_type instanceof T_Callable ? $container_callable_type : null;
                $candidate_callable = Callable_Type_Comparator::get_callable_from_atomic($codebase, $atomic_type, $container_callable_type, $statements_analyzer, true);
                if ($candidate_callable && $candidate_callable !== $atomic_type) {
                    // if we had an array callable, mark it as used now, since it's not possible later
                    $potential_method_id = null;
                    if ($atomic_type instanceof T_Keyed_Array) {
                        $potential_method_id = Callable_Type_Comparator::get_callable_method_id_from_t_keyed_array($atomic_type, $codebase, $context->calling_method_id, $statements_analyzer->get_file_path());
                    } elseif ($atomic_type instanceof T_Literal_String && strpos($atomic_type->value, '::')) {
                        $parts = explode('::', $atomic_type->value);
                        $potential_method_id = new Method_Identifier($parts[0], strtolower($parts[1]));
                    }
                    if ($potential_method_id && $potential_method_id !== 'not-callable') {
                        $codebase->methods->method_exists($potential_method_id, $context->calling_method_id, $arg_location, $statements_analyzer, $statements_analyzer->get_file_path(), true, $context->inside_use());
                        if (self::verify_callable_in_context($potential_method_id, $cased_method_id, $method_id, $atomic_type, $argument_offset, $arg_location, $context, $codebase, $statements_analyzer) === false) {
                            continue;
                        }
                    }
                    $input_type->remove_type($key);
                    $input_type->add_type($candidate_callable);
                }
            }
            $input_type = $input_type->freeze();
        }
        $union_comparison_results = new Type_Comparison_Result();
        $type_match_found = Union_Type_Comparator::is_contained_by($codebase, $input_type, $param_type, true, !isset($param_type->get_atomic_types()['true']), $union_comparison_results);
        $replace_input_type = false;
        if ($union_comparison_results->replacement_union_type) {
            $replace_input_type = true;
            $input_type = $union_comparison_results->replacement_union_type;
        }
        if ($cased_method_id) {
            self::process_taintedness($statements_analyzer, $cased_method_id, $method_id, $argument_offset, $arg_location, $function_call_location, $function_param, $input_type, $input_expr, $context, $specialize_taint);
            if ($function_param->assert_untainted) {
                $input_type = $input_type->set_parent_nodes([]);
                $replace_input_type = true;
            }
        }
        if ($type_match_found && $param_type->has_callable_type()) {
            $potential_method_ids = [];
            $param_types_without_callable = array_filter($param_type->get_atomic_types(), static fn(Atomic $atomic): bool => !$atomic instanceof Atomic\T_Callable_Interface);
            $param_type_without_callable = [] !== $param_types_without_callable ? new Union($param_types_without_callable) : null;
            foreach ($input_type->get_atomic_types() as $input_type_part) {
                if ($input_type_part instanceof T_Keyed_Array) {
                    // If the param accept an array, we don't report arrays as wrong callbacks.
                    if (null !== $param_type_without_callable && Union_Type_Comparator::is_contained_by($codebase, $input_type, $param_type_without_callable)) {
                        continue;
                    }
                    $potential_method_id = Callable_Type_Comparator::get_callable_method_id_from_t_keyed_array($input_type_part, $codebase, $context->calling_method_id, $statements_analyzer->get_file_path());
                    if ($potential_method_id === null && $codebase->analysis_php_version_id >= 80200) {
                        [$lhs] = $input_type_part->properties;
                        if ($lhs->is_single_string_literal() && in_array(strtolower($lhs->get_single_string_literal()->value), ['self', 'parent', 'static'], true)) {
                            Issue_Buffer::maybe_add(new Deprecated_Constant('Use of "' . $lhs->get_single_string_literal()->value . '" in callables is deprecated', $arg_location), $statements_analyzer->get_suppressed_issues());
                        }
                    }
                    if ($potential_method_id && $potential_method_id !== 'not-callable') {
                        if (self::verify_callable_in_context($potential_method_id, $cased_method_id, $method_id, $input_type_part, $argument_offset, $arg_location, $context, $codebase, $statements_analyzer) === false) {
                            continue;
                        }
                        $potential_method_ids[] = $potential_method_id;
                    }
                } elseif ($input_type_part instanceof T_Literal_String && strpos($input_type_part->value, '::')) {
                    // If the param also accept a string, we don't report string as wrong callbacks.
                    if (null !== $param_type_without_callable && Union_Type_Comparator::is_contained_by($codebase, $input_type, $param_type_without_callable)) {
                        continue;
                    }
                    $parts = explode('::', $input_type_part->value);
                    /** @psalm-suppress PossiblyUndefinedIntArrayOffset */
                    $potential_method_id = new Method_Identifier($parts[0], strtolower($parts[1]));
                    if ($codebase->analysis_php_version_id >= 80200 && in_array(strtolower($potential_method_id->fq_class_name), ['self', 'parent', 'static'], true)) {
                        Issue_Buffer::maybe_add(new Deprecated_Constant('Use of "' . $potential_method_id->fq_class_name . '" in callables is deprecated', $arg_location), $statements_analyzer->get_suppressed_issues());
                    }
                    if (self::verify_callable_in_context($potential_method_id, $cased_method_id, $method_id, $input_type_part, $argument_offset, $arg_location, $context, $codebase, $statements_analyzer) === false) {
                        continue;
                    }
                    $potential_method_ids[] = $potential_method_id;
                }
            }
            foreach ($potential_method_ids as $potential_method_id) {
                $codebase->methods->method_exists($potential_method_id, $context->calling_method_id, $arg_location, $statements_analyzer, $statements_analyzer->get_file_path(), true, $context->inside_use());
            }
        }
        if ($context->strict_types && !$input_type->has_array() && !$param_type->from_docblock && $cased_method_id !== 'echo' && $cased_method_id !== 'print' && $cased_method_id !== 'sprintf') {
            $union_comparison_results->scalar_type_match_found = false;
            if ($union_comparison_results->to_string_cast) {
                $union_comparison_results->to_string_cast = false;
                $type_match_found = false;
            }
        }
        if ($union_comparison_results->type_coerced && !$input_type->has_mixed()) {
            if ($union_comparison_results->type_coerced_from_mixed) {
                $origin_locations = [];
                if ($statements_analyzer->data_flow_graph instanceof Variable_Use_Graph) {
                    foreach ($input_type->parent_nodes as $parent_node) {
                        $origin_locations = [...$origin_locations, ...$statements_analyzer->data_flow_graph->get_origin_locations($parent_node)];
                    }
                }
                $origin_location = count($origin_locations) === 1 ? reset($origin_locations) : null;
                if ($origin_location && $origin_location->get_hash() === $arg_location->get_hash()) {
                    $origin_location = null;
                }
                Issue_Buffer::maybe_add(new Mixed_Argument_Type_Coercion('Argument ' . ($argument_offset + 1) . $method_identifier . ' expects ' . $param_type->get_id() . ', but parent type ' . $input_type->get_id() . ' provided', $arg_location, $cased_method_id, $origin_location), $statements_analyzer->get_suppressed_issues());
            } elseif ($cased_method_id !== 'echo' && $cased_method_id !== 'print') {
                Issue_Buffer::maybe_add(new Argument_Type_Coercion('Argument ' . ($argument_offset + 1) . $method_identifier . ' expects ' . $param_type->get_id() . ', but parent type ' . $input_type->get_id() . ' provided', $arg_location, $cased_method_id), $statements_analyzer->get_suppressed_issues());
            }
        }
        if ($union_comparison_results->to_string_cast && $cased_method_id !== 'echo' && $cased_method_id !== 'print') {
            Issue_Buffer::maybe_add(new Implicit_To_String_Cast('Argument ' . ($argument_offset + 1) . $method_identifier . ' expects ' . $param_type->get_id() . ', but ' . $input_type->get_id() . ' provided with a __toString method', $arg_location), $statements_analyzer->get_suppressed_issues());
        }
        if (!$type_match_found && !$union_comparison_results->type_coerced) {
            $types_can_be_identical = Union_Type_Comparator::can_be_contained_by($codebase, $input_type, $param_type, true, true);
            $type = ($input_type->possibly_undefined ? 'possibly undefined ' : '') . $input_type->get_id();
            if ($union_comparison_results->scalar_type_match_found) {
                if ($cased_method_id !== 'echo' && $cased_method_id !== 'print') {
                    Issue_Buffer::maybe_add(new Invalid_Scalar_Argument('Argument ' . ($argument_offset + 1) . $method_identifier . ' expects ' . $param_type->get_id() . ', but ' . $type . ' provided', $arg_location, $cased_method_id), $statements_analyzer->get_suppressed_issues());
                }
            } elseif ($types_can_be_identical) {
                Issue_Buffer::maybe_add(new Possibly_Invalid_Argument('Argument ' . ($argument_offset + 1) . $method_identifier . ' expects ' . $param_type->get_id() . ', but possibly different type ' . $type . ' provided', $arg_location, $cased_method_id), $statements_analyzer->get_suppressed_issues());
            } else {
                Issue_Buffer::maybe_add(new Invalid_Argument('Argument ' . ($argument_offset + 1) . $method_identifier . ' expects ' . $param_type->get_id() . ', but ' . $type . ($union_comparison_results->missing_shape_fields ? ' with additional array shape fields (' . implode(', ', $union_comparison_results->missing_shape_fields) . ') was' : '') . ' provided', $arg_location, $cased_method_id), $statements_analyzer->get_suppressed_issues());
            }
            return null;
        }
        if ($input_expr instanceof Php_Parser\Node\Scalar\String_ || $input_expr instanceof Php_Parser\Node\Expr\Array_ || $input_expr instanceof Php_Parser\Node\Expr\Binary_Op\Concat) {
            self::verify_explicit_param($statements_analyzer, $param_type, $arg_location, $input_expr, $context);
            return null;
        }
        if (!$param_type->is_nullable() && $cased_method_id !== 'echo' && $cased_method_id !== 'print') {
            if ($input_type->is_null()) {
                Issue_Buffer::maybe_add(new Null_Argument('Argument ' . ($argument_offset + 1) . $method_identifier . ' cannot be null, ' . 'null value provided to parameter with type ' . $param_type->get_id(), $arg_location, $cased_method_id), $statements_analyzer->get_suppressed_issues());
                return null;
            }
            if ($input_type->is_nullable() && !$input_type->ignore_nullable_issues) {
                Issue_Buffer::maybe_add(new Possibly_Null_Argument('Argument ' . ($argument_offset + 1) . $method_identifier . ' cannot be null, possibly ' . 'null value provided', $arg_location, $cased_method_id), $statements_analyzer->get_suppressed_issues());
            }
        }
        if (!$param_type->is_falsable() && !$param_type->has_bool() && !$param_type->has_scalar() && $cased_method_id !== 'echo' && $cased_method_id !== 'print') {
            if ($input_type->is_false()) {
                Issue_Buffer::maybe_add(new Invalid_Argument('Argument ' . ($argument_offset + 1) . $method_identifier . ' cannot be false, ' . $param_type->get_id() . ' value expected', $arg_location, $cased_method_id), $statements_analyzer->get_suppressed_issues());
                return null;
            }
            if ($input_type->is_falsable() && !$input_type->ignore_falsable_issues) {
                Issue_Buffer::maybe_add(new Possibly_False_Argument('Argument ' . ($argument_offset + 1) . $method_identifier . ' cannot be false, possibly ' . $param_type->get_id() . ' value expected', $arg_location, $cased_method_id), $statements_analyzer->get_suppressed_issues());
            }
        }
        if (($type_match_found || $input_type->has_mixed()) && !$function_param->by_ref && !($function_param->is_variadic xor $unpack) && $cased_method_id !== 'echo' && $cased_method_id !== 'print' && (!$in_call_map || $context->strict_types)) {
            self::coerce_value_after_gatekeeper_argument($statements_analyzer, $input_type, $replace_input_type, $input_expr, $param_type, $signature_param_type, $context, $unpack, $unpacked_atomic_array);
        }
        return null;
    }
    private static function verify_callable_in_context(Method_Identifier $potential_method_id, ?string $cased_method_id, ?Method_Identifier $method_id, Atomic $input_type_part, int $argument_offset, Code_Location $arg_location, Context $context, Codebase $codebase, Statements_Analyzer $statements_analyzer): ?bool
    {
        $method_identifier = $cased_method_id !== null ? ' of ' . $cased_method_id : '';
        if (!$method_id || $potential_method_id->fq_class_name !== $context->self || $method_id->fq_class_name !== $context->self) {
            if ($input_type_part instanceof T_Keyed_Array) {
                [$lhs] = $input_type_part->properties;
            } else {
                $lhs = Type::get_string($potential_method_id->fq_class_name);
            }
            try {
                $method_storage = $codebase->methods->get_storage($potential_method_id);
                $lhs_atomic = $lhs->get_single_atomic();
                if ($lhs->is_single() && $lhs->has_named_object_type() && ($lhs->is_static_object() || $lhs_atomic instanceof T_Named_Object && !$lhs_atomic->definite_class && $lhs_atomic->value === $context->self)) {
                    if ($potential_method_id->fq_class_name !== $context->self || $cased_method_id !== null && !$method_id && !in_array($cased_method_id, self::PHP_NATIVE_NON_PUBLIC_CB, true) || $method_id && $method_id->fq_class_name !== $context->self && $method_id->fq_class_name !== 'Closure') {
                        if ($method_storage->visibility !== Class_Like_Analyzer::VISIBILITY_PUBLIC) {
                            Issue_Buffer::maybe_add(new Invalid_Argument('Argument ' . ($argument_offset + 1) . $method_identifier . ' expects a public callable, but a non-public callable provided', $arg_location, $cased_method_id), $statements_analyzer->get_suppressed_issues());
                            return false;
                        }
                    }
                } elseif ($lhs->is_single()) {
                    // instance from e.g. new Foo() or static string like Foo::bar
                    if (!$method_storage->is_static && !$lhs->has_named_object_type() || $method_storage->visibility !== Class_Like_Analyzer::VISIBILITY_PUBLIC) {
                        Issue_Buffer::maybe_add(new Invalid_Argument('Argument ' . ($argument_offset + 1) . $method_identifier . ' expects a public static callable, but a ' . ($method_storage->visibility !== Class_Like_Analyzer::VISIBILITY_PUBLIC ? 'non-public ' : '') . (!$method_storage->is_static ? 'non-static ' : '') . 'callable provided', $arg_location, $cased_method_id), $statements_analyzer->get_suppressed_issues());
                        return false;
                    }
                }
            } catch (UnexpectedValueException) {
                // do nothing
            }
        }
        return null;
    }
    /**
     * @param PhpParser\Node\Scalar\String_|PhpParser\Node\Expr\Array_|PhpParser\Node\Expr\BinaryOp\Concat $input_expr
     */
    private static function verify_explicit_param(Statements_Analyzer $statements_analyzer, Union $param_type, Code_Location $arg_location, Php_Parser\Node\Expr $input_expr, Context $context): void
    {
        $codebase = $statements_analyzer->get_codebase();
        foreach ($param_type->get_atomic_types() as $param_type_part) {
            if ($param_type_part instanceof T_Class_String && $input_expr instanceof Php_Parser\Node\Scalar\String_ && $param_type->is_single()) {
                if (Class_Like_Analyzer::check_fully_qualified_class_like_name($statements_analyzer, $input_expr->value, $arg_location, $context->self, $context->calling_method_id, $statements_analyzer->get_suppressed_issues(), new Class_Like_Name_Options(true)) === false) {
                    return;
                }
            } elseif ($param_type_part instanceof T_Array && $input_expr instanceof Php_Parser\Node\Expr\Array_) {
                foreach ($param_type_part->type_params[1]->get_atomic_types() as $param_array_type_part) {
                    if ($param_array_type_part instanceof T_Class_String) {
                        foreach ($input_expr->items as $item) {
                            if ($item && $item->value instanceof Php_Parser\Node\Scalar\String_) {
                                if (Class_Like_Analyzer::check_fully_qualified_class_like_name($statements_analyzer, $item->value->value, $arg_location, $context->self, $context->calling_method_id, $statements_analyzer->get_suppressed_issues(), new Class_Like_Name_Options(true)) === false) {
                                    return;
                                }
                            }
                        }
                    }
                }
            } elseif ($param_type_part instanceof T_Callable) {
                $can_be_callable_like_array = false;
                if ($param_type->has_array()) {
                    $param_array_type = $param_type->get_array();
                    $row_type = null;
                    if ($param_array_type instanceof T_Array) {
                        $row_type = $param_array_type->type_params[1];
                    } elseif ($param_array_type instanceof T_Keyed_Array) {
                        $row_type = $param_array_type->get_generic_value_type();
                    }
                    if ($row_type && ($row_type->has_mixed() || $row_type->has_string())) {
                        $can_be_callable_like_array = true;
                    }
                }
                if (!$can_be_callable_like_array) {
                    $function_ids = Call_Analyzer::get_function_ids_from_callable_arg($statements_analyzer, $input_expr);
                    foreach ($function_ids as $function_id) {
                        if (str_contains($function_id, '::')) {
                            if ($function_id[0] === '$') {
                                $function_id = substr($function_id, 1);
                            }
                            $function_id_parts = explode('&', $function_id);
                            $non_existent_method_ids = [];
                            foreach ($function_id_parts as $function_id_part) {
                                [$callable_fq_class_name, $method_name] = explode('::', $function_id_part);
                                switch ($callable_fq_class_name) {
                                    case 'self':
                                    case 'static':
                                    case 'parent':
                                        $container_class = $statements_analyzer->get_fqcln();
                                        if ($callable_fq_class_name === 'parent') {
                                            $container_class = $statements_analyzer->get_parent_fqcln();
                                            if ($container_class === null) {
                                                Issue_Buffer::accepts(new Parent_Not_Found('Cannot call method on parent' . ' as this class does not extend another', $arg_location), $statements_analyzer->get_suppressed_issues());
                                            }
                                        }
                                        if (!$container_class) {
                                            continue 2;
                                        }
                                        $callable_fq_class_name = $container_class;
                                }
                                if (Class_Like_Analyzer::check_fully_qualified_class_like_name($statements_analyzer, $callable_fq_class_name, $arg_location, $context->self, $context->calling_method_id, $statements_analyzer->get_suppressed_issues(), new Class_Like_Name_Options(true)) === false) {
                                    return;
                                }
                                $function_id_part = new Method_Identifier($callable_fq_class_name, strtolower($method_name));
                                $call_method_id = new Method_Identifier($callable_fq_class_name, '__call');
                                if (!$codebase->class_or_interface_or_enum_exists($callable_fq_class_name)) {
                                    return;
                                }
                                if (!$codebase->methods->method_exists($function_id_part) && !$codebase->methods->method_exists($call_method_id)) {
                                    $non_existent_method_ids[] = $function_id_part;
                                }
                            }
                            if ($non_existent_method_ids && !$param_type->has_string() && !$param_type->has_array()) {
                                if (Method_Analyzer::check_method_exists($codebase, $non_existent_method_ids[0], $arg_location, $statements_analyzer->get_suppressed_issues()) === false) {
                                    return;
                                }
                            }
                        } else if (!$param_type->has_string() && !$param_type->has_array() && $context->check_functions && Call_Analyzer::check_function_exists($statements_analyzer, $function_id, $arg_location, false) === false) {
                            return;
                        }
                    }
                }
            }
        }
    }
    /**
     * @param TKeyedArray|TArray|TClassStringMap $unpacked_atomic_array
     */
    private static function coerce_value_after_gatekeeper_argument(Statements_Analyzer $statements_analyzer, Union $input_type, bool $input_type_changed, Php_Parser\Node\Expr $input_expr, Union $param_type, ?Union $signature_param_type, Context $context, bool $unpack, ?Atomic $unpacked_atomic_array): void
    {
        if ($param_type->has_mixed()) {
            return;
        }
        $var_id = Expression_Identifier::get_var_id($input_expr, $statements_analyzer->get_fqcln(), $statements_analyzer);
        if (!$var_id) {
            return;
        }
        if (!$input_type_changed && $param_type->from_docblock && !$input_type->has_mixed()) {
            $types = $input_type->get_atomic_types();
            foreach ($param_type->get_atomic_types() as $param_atomic_type) {
                if ($param_atomic_type instanceof T_Generic_Object) {
                    foreach ($types as &$input_atomic_type) {
                        if ($input_atomic_type instanceof T_Generic_Object && $input_atomic_type->value === $param_atomic_type->value) {
                            $new_type_params = [];
                            foreach ($input_atomic_type->type_params as $i => $type_param) {
                                if ($type_param->is_never() && isset($param_atomic_type->type_params[$i])) {
                                    $input_type_changed = true;
                                    $new_type_params[$i] = $param_atomic_type->type_params[$i];
                                }
                            }
                            if ($new_type_params) {
                                $input_atomic_type = new T_Generic_Object($input_atomic_type->value, [...$input_atomic_type->type_params, ...$new_type_params], $input_atomic_type->remapped_params, false, $input_atomic_type->extra_types);
                            }
                        }
                    }
                    unset($input_atomic_type);
                }
            }
            if (!$input_type_changed) {
                return;
            }
            $input_type = new Union($types);
        }
        $was_cloned = false;
        if ($input_type->is_nullable() && !$param_type->is_nullable()) {
            $input_type = $input_type->get_builder();
            $was_cloned = true;
            $input_type->remove_type('null');
            $input_type = $input_type->freeze();
        }
        if ($input_type->get_id() === $param_type->get_id()) {
            if ($input_type->from_docblock) {
                $input_type = $input_type->set_from_docblock(false);
            }
        } elseif ($input_type->has_mixed() && $signature_param_type) {
            $was_cloned = true;
            $parent_nodes = $input_type->parent_nodes;
            $by_ref = $input_type->by_ref;
            $input_type = $signature_param_type->set_properties(['ignore_nullable_issues' => $signature_param_type->is_nullable(), 'parent_nodes' => $parent_nodes, 'by_ref' => $by_ref]);
        }
        if ($context->inside_conditional && !isset($context->assigned_var_ids[$var_id])) {
            $context->assigned_var_ids[$var_id] = 0;
        }
        if ($was_cloned) {
            $context->remove_var_from_conflicting_clauses($var_id, null, $statements_analyzer);
        }
        if ($unpack) {
            if ($unpacked_atomic_array instanceof T_Array) {
                $unpacked_atomic_array = $unpacked_atomic_array->set_type_params([$unpacked_atomic_array->type_params[0], $input_type]);
                $context->vars_in_scope[$var_id] = new Union([$unpacked_atomic_array]);
            } elseif ($unpacked_atomic_array instanceof T_Keyed_Array && $unpacked_atomic_array->is_list) {
                if ($unpacked_atomic_array->is_non_empty()) {
                    $unpacked_atomic_array = Type::get_non_empty_list_atomic($input_type);
                } else {
                    $unpacked_atomic_array = Type::get_list_atomic($input_type);
                }
                $context->vars_in_scope[$var_id] = new Union([$unpacked_atomic_array]);
            } else {
                $context->vars_in_scope[$var_id] = new Union([new T_Array([Type::get_int(), $input_type])]);
            }
        } else {
            $context->vars_in_scope[$var_id] = $input_type;
        }
    }
    private static function process_taintedness(Statements_Analyzer $statements_analyzer, string $cased_method_id, ?Method_Identifier $method_id, int $argument_offset, Code_Location $arg_location, Code_Location $function_call_location, Function_Like_Parameter $function_param, Union $input_type, Php_Parser\Node\Expr $expr, Context $context, bool $specialize_taint): void
    {
        $codebase = $statements_analyzer->get_codebase();
        if (!$statements_analyzer->data_flow_graph || $statements_analyzer->data_flow_graph instanceof Taint_Flow_Graph && in_array('TaintedInput', $statements_analyzer->get_suppressed_issues())) {
            return;
        }
        // literal data can’t be tainted
        if ($statements_analyzer->data_flow_graph instanceof Taint_Flow_Graph && $input_type->is_single() && $input_type->has_literal_value()) {
            return;
        }
        // numeric types can't be tainted, neither can bool
        if ($statements_analyzer->data_flow_graph instanceof Taint_Flow_Graph && $input_type->is_single() && ($input_type->is_int() || $input_type->is_float() || $input_type->is_bool())) {
            return;
        }
        $event = new Add_Remove_Taints_Event($expr, $context, $statements_analyzer, $codebase);
        $added_taints = $codebase->config->event_dispatcher->dispatch_add_taints($event);
        $removed_taints = $codebase->config->event_dispatcher->dispatch_remove_taints($event);
        if ($function_param->type && $function_param->type->is_string() && !$input_type->is_string()) {
            $input_type = Cast_Analyzer::cast_string_attempt($statements_analyzer, $context, $input_type, $expr, false);
        }
        if ($specialize_taint) {
            $method_node = Data_Flow_Node::get_for_method_argument($cased_method_id, $cased_method_id, $argument_offset, $statements_analyzer->data_flow_graph instanceof Taint_Flow_Graph ? $function_param->location : null, $function_call_location);
        } else {
            $method_node = Data_Flow_Node::get_for_method_argument($cased_method_id, $cased_method_id, $argument_offset, $statements_analyzer->data_flow_graph instanceof Taint_Flow_Graph ? $function_param->location : null);
            if ($statements_analyzer->data_flow_graph instanceof Taint_Flow_Graph && $method_id && $method_id->method_name !== '__construct') {
                $fq_classlike_name = $method_id->fq_class_name;
                $method_name = $method_id->method_name;
                $cased_method_name = explode('::', $cased_method_id)[1];
                $class_storage = $codebase->classlike_storage_provider->get($fq_classlike_name);
                foreach ($class_storage->dependent_classlikes as $dependent_classlike_lc => $_) {
                    $dependent_classlike_storage = $codebase->classlike_storage_provider->get($dependent_classlike_lc);
                    $new_sink = Data_Flow_Node::get_for_method_argument($dependent_classlike_lc . '::' . $method_name, $dependent_classlike_storage->name . '::' . $cased_method_name, $argument_offset, $arg_location);
                    $statements_analyzer->data_flow_graph->add_node($new_sink);
                    $statements_analyzer->data_flow_graph->add_path($method_node, $new_sink, 'arg', $added_taints, $removed_taints);
                }
            }
        }
        if ($method_id && $statements_analyzer->data_flow_graph instanceof Taint_Flow_Graph) {
            $declaring_method_id = $codebase->methods->get_declaring_method_id($method_id);
            if ($declaring_method_id && (string) $declaring_method_id !== (string) $method_id) {
                $new_sink = Data_Flow_Node::get_for_method_argument((string) $declaring_method_id, $codebase->methods->get_cased_method_id($declaring_method_id), $argument_offset, $arg_location);
                $statements_analyzer->data_flow_graph->add_node($new_sink);
                $statements_analyzer->data_flow_graph->add_path($method_node, $new_sink, 'arg', $added_taints, $removed_taints);
            }
        }
        $statements_analyzer->data_flow_graph->add_node($method_node);
        $argument_value_node = Data_Flow_Node::get_for_assignment('call to ' . $cased_method_id, $arg_location);
        $statements_analyzer->data_flow_graph->add_node($argument_value_node);
        $statements_analyzer->data_flow_graph->add_path($argument_value_node, $method_node, 'arg', $added_taints, $removed_taints);
        foreach ($input_type->parent_nodes as $parent_node) {
            $statements_analyzer->data_flow_graph->add_node($method_node);
            $statements_analyzer->data_flow_graph->add_path($parent_node, $argument_value_node, 'arg', $added_taints, $removed_taints);
        }
        $taints = array_diff($added_taints, $removed_taints);
        if ($taints !== [] && $statements_analyzer->data_flow_graph instanceof Taint_Flow_Graph) {
            $taint_source = Taint_Source::from_node($argument_value_node);
            $taint_source->taints = $taints;
            $statements_analyzer->data_flow_graph->add_source($taint_source);
        }
    }
}