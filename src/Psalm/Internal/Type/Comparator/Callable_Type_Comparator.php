<?php

declare (strict_types=1);
namespace Psalm\Internal\Type\Comparator;

use Exception;
use Php_Parser\Node\Arg;
use Php_Parser\Node\Expr\Variable;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Codebase\Internal_Call_Map_Handler;
use Psalm\Internal\Method_Identifier;
use Psalm\Internal\Provider\Node_Data_Provider;
use Psalm\Internal\Type\Template_Inferred_Type_Replacer;
use Psalm\Internal\Type\Template_Result;
use Psalm\Internal\Type\Template_Standin_Type_Replacer;
use Psalm\Internal\Type\Type_Expander;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Callable;
use Psalm\Type\Atomic\T_Callable_Interface;
use Psalm\Type\Atomic\T_Class_String;
use Psalm\Type\Atomic\T_Closure;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Atomic\T_Literal_String;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Union;
use UnexpectedValueException;
use function array_slice;
use function count;
use function end;
use function strtolower;
use function substr;
/**
 * @internal
 */
final class Callable_Type_Comparator
{
    /**
     * @param  TCallable|TClosure   $container_type_part
     */
    public static function is_contained_by(Codebase $codebase, T_Closure|T_Callable_Interface $input_type_part, Atomic $container_type_part, ?Type_Comparison_Result $atomic_comparison_result): bool
    {
        if ($container_type_part instanceof T_Closure) {
            if ($input_type_part instanceof T_Callable_Interface && !$input_type_part instanceof T_Callable) {
                if ($atomic_comparison_result) {
                    $atomic_comparison_result->type_coerced = true;
                }
                return false;
            }
        }
        if ($input_type_part instanceof T_Callable_Interface && !$input_type_part instanceof T_Callable) {
            return true;
        }
        if ($container_type_part->is_pure && !$input_type_part->is_pure) {
            if ($atomic_comparison_result) {
                $atomic_comparison_result->type_coerced = $input_type_part->is_pure === null;
            }
            return false;
        }
        if ($container_type_part->params !== null && $input_type_part->params === null) {
            if ($atomic_comparison_result) {
                $atomic_comparison_result->type_coerced = true;
                $atomic_comparison_result->type_coerced_from_mixed = true;
            }
            return false;
        }
        $input_variadic_param_idx = null;
        if ($input_type_part->params !== null && $container_type_part->params !== null) {
            foreach ($input_type_part->params as $i => $input_param) {
                $container_param = null;
                if (isset($container_type_part->params[$i])) {
                    $container_param = $container_type_part->params[$i];
                } elseif ($container_type_part->params) {
                    $last_param = end($container_type_part->params);
                    if ($last_param->is_variadic) {
                        $container_param = $last_param;
                    }
                }
                if ($input_param->is_variadic) {
                    $input_variadic_param_idx = $i;
                }
                if (!$container_param) {
                    if ($input_param->is_variadic) {
                        break;
                    }
                    if ($input_param->is_optional) {
                        break;
                    }
                    return false;
                }
                if ($container_param->type && !$container_param->type->has_mixed() && !Union_Type_Comparator::is_contained_by($codebase, $container_param->type, $input_param->type ?: Type::get_mixed(), false, false, $atomic_comparison_result)) {
                    return false;
                }
            }
        }
        if ($input_variadic_param_idx && isset($input_type_part->params[$input_variadic_param_idx])) {
            $input_param = $input_type_part->params[$input_variadic_param_idx];
            foreach (array_slice($container_type_part->params ?? [], $input_variadic_param_idx) as $container_param) {
                if ($container_param->type && !$container_param->type->has_mixed() && !Union_Type_Comparator::is_contained_by($codebase, $container_param->type, $input_param->type ?: Type::get_mixed(), false, false, $atomic_comparison_result)) {
                    return false;
                }
            }
        }
        if (isset($container_type_part->return_type)) {
            if (!isset($input_type_part->return_type)) {
                if ($atomic_comparison_result) {
                    $atomic_comparison_result->type_coerced = true;
                    $atomic_comparison_result->type_coerced_from_mixed = true;
                }
                return false;
            }
            $input_return = $input_type_part->return_type;
            if ($input_return->is_void() && $container_type_part->return_type->is_nullable()) {
                return true;
            }
            if (!$container_type_part->return_type->is_void() && !Union_Type_Comparator::is_contained_by($codebase, $input_return, $container_type_part->return_type, false, false, $atomic_comparison_result)) {
                return false;
            }
        }
        return true;
    }
    public static function is_not_explicitly_callable_type_callable(Codebase $codebase, Atomic $input_type_part, T_Callable $container_type_part, ?Type_Comparison_Result $atomic_comparison_result): bool
    {
        if ($input_type_part instanceof T_Array) {
            if ($input_type_part->type_params[1]->is_mixed() || $input_type_part->type_params[1]->has_scalar()) {
                if ($atomic_comparison_result) {
                    $atomic_comparison_result->type_coerced_from_mixed = true;
                    $atomic_comparison_result->type_coerced = true;
                }
                return false;
            }
            if (!$input_type_part->type_params[1]->has_string()) {
                return false;
            }
        } elseif ($input_type_part instanceof T_Keyed_Array) {
            $method_id = self::get_callable_method_id_from_t_keyed_array($input_type_part);
            if ($method_id === 'not-callable') {
                return false;
            }
            if (!$method_id) {
                return true;
            }
            try {
                $method_id = $codebase->methods->get_declaring_method_id($method_id);
                if (!$method_id) {
                    return false;
                }
                if (!$codebase->methods->has_storage($method_id)) {
                    return false;
                }
            } catch (Exception) {
                return false;
            }
        }
        $input_callable = self::get_callable_from_atomic($codebase, $input_type_part, $container_type_part, null, true);
        if ($input_callable) {
            if (self::is_contained_by($codebase, $input_callable, $container_type_part, $atomic_comparison_result) === false) {
                return false;
            }
        }
        return true;
    }
    /**
     * @return TCallable|TClosure|null
     */
    public static function get_callable_from_atomic(Codebase $codebase, Atomic $input_type_part, ?T_Callable $container_type_part = null, ?Statements_Analyzer $statements_analyzer = null, bool $expand_callable = false): ?Atomic
    {
        if ($input_type_part instanceof T_Callable || $input_type_part instanceof T_Closure) {
            return $input_type_part;
        }
        if ($input_type_part instanceof T_Literal_String && $input_type_part->value) {
            try {
                $function_storage = $codebase->functions->get_storage($statements_analyzer, strtolower($input_type_part->value));
                if ($expand_callable) {
                    $params = [];
                    foreach ($function_storage->params as $param) {
                        if ($param->type) {
                            $param = $param->set_type(Type_Expander::expand_union($codebase, $param->type, null, null, null, true, true, false, false, true));
                        }
                        $params[] = $param;
                    }
                    $return_type = null;
                    if ($function_storage->return_type) {
                        $return_type = Type_Expander::expand_union($codebase, $function_storage->return_type, null, null, null, true, true, false, false, true);
                    }
                } else {
                    $return_type = $function_storage->return_type;
                    $params = $function_storage->params;
                }
                return new T_Callable('callable', $params, $return_type, $function_storage->pure);
            } catch (UnexpectedValueException) {
                if (Internal_Call_Map_Handler::in_call_map($input_type_part->value)) {
                    $args = [];
                    $nodes = new Node_Data_Provider();
                    if ($container_type_part && $container_type_part->params) {
                        foreach ($container_type_part->params as $i => $param) {
                            $arg = new Arg(new Variable('_' . $i));
                            if ($param->type) {
                                $nodes->set_type($arg->value, $param->type);
                            }
                            $args[] = $arg;
                        }
                    }
                    $matching_callable = Internal_Call_Map_Handler::get_callable_from_call_map_by_id($codebase, $input_type_part->value, $args, $nodes);
                    $must_use = false;
                    $matching_callable = $matching_callable->set_is_pure($codebase->functions->is_call_map_function_pure($codebase, $statements_analyzer->node_data ?? null, $input_type_part->value, null, $must_use));
                    return $matching_callable;
                }
            }
        } elseif ($input_type_part instanceof T_Keyed_Array) {
            $method_id = self::get_callable_method_id_from_t_keyed_array($input_type_part);
            if ($method_id && $method_id !== 'not-callable') {
                try {
                    $method_storage = $codebase->methods->get_storage($method_id);
                    $method_fqcln = $method_id->fq_class_name;
                    $converted_return_type = null;
                    if ($method_storage->return_type) {
                        $converted_return_type = Type_Expander::expand_union($codebase, $method_storage->return_type, $method_fqcln, $method_fqcln, null);
                    }
                    return new T_Callable('callable', $method_storage->params, $converted_return_type, $method_storage->pure);
                } catch (UnexpectedValueException) {
                    // do nothing
                }
            }
        } elseif ($input_type_part instanceof T_Named_Object && $input_type_part->value === 'Closure') {
            return new T_Callable();
        } elseif ($input_type_part instanceof T_Named_Object && $codebase->class_exists($input_type_part->value)) {
            $invoke_id = new Method_Identifier($input_type_part->value, '__invoke');
            if ($codebase->methods->method_exists($invoke_id)) {
                $declaring_method_id = $codebase->methods->get_declaring_method_id($invoke_id);
                $template_result = null;
                if ($input_type_part instanceof Atomic\T_Generic_Object) {
                    $invokable_storage = $codebase->methods->get_class_like_storage_for_method($declaring_method_id ?? $invoke_id);
                    $type_params = [];
                    foreach ($invokable_storage->template_types ?? [] as $template => $for_class) {
                        foreach ($for_class as $type) {
                            $type_params[] = new Type\Union([new T_Template_Param($template, $type, $input_type_part->value)]);
                        }
                    }
                    if (!empty($type_params)) {
                        $input_with_templates = new Atomic\T_Generic_Object($input_type_part->value, $type_params);
                        $template_result = new Template_Result($invokable_storage->template_types ?? [], []);
                        Template_Standin_Type_Replacer::fill_template_result(new Type\Union([$input_with_templates]), $template_result, $codebase, null, new Type\Union([$input_type_part]));
                    }
                }
                if ($declaring_method_id) {
                    $method_storage = $codebase->methods->get_storage($declaring_method_id);
                    $method_fqcln = $invoke_id->fq_class_name;
                    $converted_return_type = null;
                    if ($method_storage->return_type) {
                        $converted_return_type = Type_Expander::expand_union($codebase, $method_storage->return_type, $method_fqcln, $method_fqcln, null);
                    }
                    $callable = new T_Callable('callable', $method_storage->params, $converted_return_type, $method_storage->pure);
                    if ($template_result) {
                        return Template_Inferred_Type_Replacer::replace(new Union([$callable]), $template_result, $codebase)->get_single_atomic();
                    }
                    return $callable;
                }
            }
        }
        return null;
    }
    /** @return null|'not-callable'|MethodIdentifier */
    public static function get_callable_method_id_from_t_keyed_array(T_Keyed_Array $input_type_part, ?Codebase $codebase = null, ?string $calling_method_id = null, ?string $file_name = null): string|Method_Identifier|null
    {
        if (!isset($input_type_part->properties[0]) || !isset($input_type_part->properties[1]) || count($input_type_part->properties) > 2) {
            return 'not-callable';
        }
        [$lhs, $rhs] = $input_type_part->properties;
        $rhs_low_info = $rhs->has_mixed() || $rhs->has_scalar();
        if ($rhs_low_info || !$rhs->is_single_string_literal()) {
            if (!$rhs_low_info && !$rhs->has_string()) {
                return 'not-callable';
            }
            if ($codebase && ($calling_method_id || $file_name)) {
                foreach ($lhs->get_atomic_types() as $lhs_atomic_type) {
                    if ($lhs_atomic_type instanceof T_Named_Object) {
                        $codebase->analyzer->add_mixed_member_name(strtolower($lhs_atomic_type->value) . '::', $calling_method_id ?: $file_name);
                    } elseif ($lhs_atomic_type instanceof T_Template_Param) {
                        $lhs_template_type = $lhs_atomic_type->as;
                        if ($lhs_template_type->is_single()) {
                            $lhs_template_atomic_type = $lhs_template_type->get_single_atomic();
                            $member_id = null;
                            if ($lhs_template_atomic_type instanceof T_Named_Object) {
                                $member_id = $lhs_template_atomic_type->value;
                            } elseif ($lhs_template_atomic_type instanceof T_Class_String) {
                                $member_id = $lhs_template_atomic_type->as;
                            }
                            if ($member_id) {
                                /** @psalm-suppress PossiblyNullArgument Psalm bug */
                                $codebase->analyzer->add_mixed_member_name(strtolower($member_id) . '::', $calling_method_id ?: $file_name);
                            }
                        }
                    }
                }
            }
            return null;
        }
        $method_name = $rhs->get_single_string_literal()->value;
        $class_name = null;
        if ($lhs->is_single_string_literal()) {
            $class_name = $lhs->get_single_string_literal()->value;
            if ($class_name[0] === '\\') {
                $class_name = substr($class_name, 1);
            }
        } elseif ($lhs->is_single()) {
            foreach ($lhs->get_atomic_types() as $lhs_atomic_type) {
                if ($lhs_atomic_type instanceof T_Named_Object) {
                    $class_name = $lhs_atomic_type->value;
                } elseif ($lhs_atomic_type instanceof T_Template_Param) {
                    $lhs_template_type = $lhs_atomic_type->as;
                    if ($lhs_template_type->is_single()) {
                        $lhs_template_atomic_type = $lhs_template_type->get_single_atomic();
                        if ($lhs_template_atomic_type instanceof T_Named_Object) {
                            $class_name = $lhs_template_atomic_type->value;
                        } elseif ($lhs_template_atomic_type instanceof T_Class_String) {
                            $class_name = $lhs_template_atomic_type->as;
                        }
                    }
                } elseif ($lhs_atomic_type instanceof T_Class_String && $lhs_atomic_type->as) {
                    $class_name = $lhs_atomic_type->as;
                }
            }
        }
        if ($class_name === 'self' || $class_name === 'static' || $class_name === 'parent') {
            return null;
        }
        if (!$class_name) {
            if ($codebase && ($calling_method_id || $file_name)) {
                $codebase->analyzer->add_mixed_member_name(strtolower($method_name), $calling_method_id ?: $file_name);
            }
            return null;
        }
        return new Method_Identifier($class_name, strtolower($method_name));
    }
}