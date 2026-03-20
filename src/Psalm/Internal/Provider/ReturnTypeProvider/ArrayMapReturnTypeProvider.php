<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider\Return_Type_Provider;

use Override;
use Php_Parser;
use Psalm\Context;
use Psalm\Internal\Analyzer\Statements\Expression\Assertion_Finder;
use Psalm\Internal\Analyzer\Statements\Expression\Call\Function_Call_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call\Method_Call_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call\Static_Call_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Type\Array_Type;
use Psalm\Node\Expr\Virtual_Array_Dim_Fetch;
use Psalm\Node\Expr\Virtual_Func_Call;
use Psalm\Node\Expr\Virtual_Method_Call;
use Psalm\Node\Expr\Virtual_Static_Call;
use Psalm\Node\Expr\Virtual_Variable;
use Psalm\Node\Name\Virtual_Fully_Qualified;
use Psalm\Node\Virtual_Arg;
use Psalm\Node\Virtual_Identifier;
use Psalm\Plugin\Event_Handler\Event\Function_Return_Type_Provider_Event;
use Psalm\Plugin\Event_Handler\Function_Return_Type_Provider_Interface;
use Psalm\Storage\Assertion;
use Psalm\Type;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Non_Empty_Array;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Atomic\T_Template_Param_Class;
use Psalm\Type\Union;
use UnexpectedValueException;
use function array_map;
use function array_shift;
use function array_slice;
use function array_values;
use function assert;
use function count;
use function explode;
use function in_array;
use function mt_rand;
use function reset;
use function str_contains;
use function substr;
/**
 * @internal
 */
final class Array_Map_Return_Type_Provider implements Function_Return_Type_Provider_Interface
{
    /**
     * @return array<lowercase-string>
     */
    #[Override]
    public static function get_function_ids(): array
    {
        return ['array_map'];
    }
    #[Override]
    public static function get_function_return_type(Function_Return_Type_Provider_Event $event): Union
    {
        $statements_source = $event->get_statements_source();
        $call_args = $event->get_call_args();
        $context = $event->get_context();
        if (!$statements_source instanceof Statements_Analyzer) {
            return Type::get_mixed();
        }
        $function_call_arg = $call_args[0] ?? null;
        $function_call_type = $function_call_arg ? $statements_source->node_data->get_type($function_call_arg->value) : null;
        if ($function_call_type && $function_call_type->is_null()) {
            array_shift($call_args);
            if (!$call_args) {
                return Type::get_never();
            }
            $array_arg_types = [];
            $orig_types = [];
            foreach ($call_args as $call_arg) {
                $call_arg_type = $statements_source->node_data->get_type($call_arg->value);
                if ($call_arg_type && $call_arg_type->is_single() && ($call_arg_atomic = $call_arg_type->get_single_atomic()) instanceof T_Keyed_Array && $call_arg_atomic->fallback_params === null) {
                    $array_arg_types[] = array_values($call_arg_atomic->properties);
                    $orig_types[] = $call_arg_type;
                } elseif ($call_arg_type && $call_arg_type->is_single() && ($call_arg_atomic = $call_arg_type->get_single_atomic()) instanceof T_Array && $call_arg_atomic->is_empty_array()) {
                    $array_arg_types[] = [];
                    $orig_types[] = $call_arg_type;
                } else {
                    return Type::get_array();
                }
            }
            if (count($orig_types) === 1) {
                return $orig_types[0];
            }
            $null = Type::get_null();
            $array_arg_types = array_map(null, ...$array_arg_types);
            $array_arg_types = array_map(
                /** @param non-empty-array<?Union> $sub */
                static function (array $sub) use ($null): \Psalm\Type\Union {
                    $sub = array_map(static fn(?Union $t): \Psalm\Type\Union => $t ?? $null, $sub);
                    return new Union([new T_Keyed_Array($sub, null, null, true)]);
                },
                $array_arg_types
            );
            if (!$array_arg_types) {
                return Type::get_empty_array();
            }
            return new Union([new T_Keyed_Array($array_arg_types, null, null, true)]);
        }
        $array_arg = $call_args[1] ?? null;
        if (!$array_arg) {
            return Type::get_array();
        }
        $array_arg_atomic_type = null;
        $array_arg_type = null;
        if ($array_arg_union_type = $statements_source->node_data->get_type($array_arg->value)) {
            $arg_types = $array_arg_union_type->get_atomic_types();
            if (isset($arg_types['array'])) {
                $array_arg_atomic_type = $arg_types['array'];
                $array_arg_type = Array_Type::infer($array_arg_atomic_type);
            }
        }
        $generic_key_type = null;
        $mapping_return_type = null;
        if ($function_call_arg && $function_call_type) {
            if (count($call_args) === 2) {
                $generic_key_type = $array_arg_type->key ?? Type::get_array_key();
            } else {
                $generic_key_type = Type::get_int();
            }
            if ($function_call_type->has_callable_type()) {
                $closure_types = $function_call_type->get_closure_types() ?: $function_call_type->get_callable_types();
                $closure_atomic_type = reset($closure_types);
                assert($closure_atomic_type !== false);
                $closure_return_type = $closure_atomic_type->return_type ?: Type::get_mixed();
                if ($closure_return_type->is_void()) {
                    $closure_return_type = Type::get_null();
                }
                $mapping_return_type = $closure_return_type;
            } elseif ($function_call_arg->value instanceof Php_Parser\Node\Scalar\String_ || $function_call_arg->value instanceof Php_Parser\Node\Expr\Array_ || $function_call_arg->value instanceof Php_Parser\Node\Expr\Binary_Op\Concat) {
                $mapping_function_ids = Call_Analyzer::get_function_ids_from_callable_arg($statements_source, $function_call_arg->value);
                if ($mapping_function_ids) {
                    $mapping_return_type = self::get_return_type_from_mapping_ids($statements_source, $mapping_function_ids, $context, $function_call_arg, array_slice($call_args, 1));
                }
                if ($function_call_arg->value instanceof Php_Parser\Node\Expr\Array_ && isset($function_call_arg->value->items[0]) && isset($function_call_arg->value->items[1]) && $function_call_arg->value->items[1]->value instanceof Php_Parser\Node\Scalar\String_ && $function_call_arg->value->items[0]->value instanceof Php_Parser\Node\Expr\Variable && $variable_type = $statements_source->node_data->get_type($function_call_arg->value->items[0]->value)) {
                    $fake_method_call = null;
                    foreach ($variable_type->get_atomic_types() as $variable_atomic_type) {
                        if ($variable_atomic_type instanceof T_Template_Param || $variable_atomic_type instanceof T_Template_Param_Class) {
                            $fake_method_call = new Virtual_Static_Call($function_call_arg->value->items[0]->value, $function_call_arg->value->items[1]->value->value, []);
                        }
                    }
                    if ($fake_method_call) {
                        $fake_method_return_type = self::execute_fake_call($statements_source, $fake_method_call, $context);
                        if ($fake_method_return_type) {
                            $mapping_return_type = $fake_method_return_type;
                        }
                    }
                }
            }
        }
        if ($mapping_return_type && $generic_key_type) {
            if ($array_arg_atomic_type instanceof T_Keyed_Array && count($call_args) === 2) {
                $atomic_type = new T_Keyed_Array(array_map(static fn(Union $in): Union => $mapping_return_type->set_possibly_undefined($in->possibly_undefined), $array_arg_atomic_type->properties), null, $array_arg_atomic_type->fallback_params === null ? null : [$array_arg_atomic_type->fallback_params[0], $mapping_return_type], $array_arg_atomic_type->is_list);
                return new Union([$atomic_type]);
            }
            if ($array_arg_atomic_type instanceof T_Keyed_Array && $array_arg_atomic_type->is_list || count($call_args) !== 2) {
                if ($array_arg_atomic_type instanceof T_Keyed_Array && $array_arg_atomic_type->is_non_empty()) {
                    return Type::get_non_empty_list($mapping_return_type);
                }
                return Type::get_list($mapping_return_type);
            }
            if ($array_arg_atomic_type instanceof T_Non_Empty_Array) {
                return new Union([new T_Non_Empty_Array([$generic_key_type, $mapping_return_type])]);
            }
            return new Union([new T_Array([$generic_key_type, $mapping_return_type])]);
        }
        return count($call_args) === 2 && !($array_arg_type->is_list ?? false) ? new Union([new T_Array([$array_arg_type->key ?? Type::get_array_key(), Type::get_mixed()])]) : Type::get_list();
    }
    /**
     * @param array<string, array<array<int, Assertion>>>|null $assertions
     */
    private static function execute_fake_call(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr $fake_call, Context $context, ?array &$assertions = null): ?Union
    {
        $old_data_provider = $statements_analyzer->node_data;
        $statements_analyzer->node_data = clone $statements_analyzer->node_data;
        $suppressed_issues = $statements_analyzer->get_suppressed_issues();
        if (!in_array('PossiblyInvalidMethodCall', $suppressed_issues, true)) {
            $statements_analyzer->add_suppressed_issues(['PossiblyInvalidMethodCall']);
        }
        if (!in_array('MixedArrayOffset', $suppressed_issues, true)) {
            $statements_analyzer->add_suppressed_issues(['MixedArrayOffset']);
        }
        $was_inside_call = $context->inside_call;
        $context->inside_call = true;
        if ($fake_call instanceof Php_Parser\Node\Expr\Static_Call) {
            Static_Call_Analyzer::analyze($statements_analyzer, $fake_call, $context);
        } elseif ($fake_call instanceof Php_Parser\Node\Expr\Method_Call) {
            Method_Call_Analyzer::analyze($statements_analyzer, $fake_call, $context);
        } elseif ($fake_call instanceof Php_Parser\Node\Expr\Func_Call) {
            Function_Call_Analyzer::analyze($statements_analyzer, $fake_call, $context);
        } else {
            throw new UnexpectedValueException('UnrecognizedCall');
        }
        $codebase = $statements_analyzer->get_codebase();
        if ($assertions !== null) {
            $anded_assertions = Assertion_Finder::scrape_assertions($fake_call, null, $statements_analyzer, $codebase);
            $assertions = $anded_assertions[0] ?? [];
        }
        $context->inside_call = $was_inside_call;
        if (!in_array('PossiblyInvalidMethodCall', $suppressed_issues, true)) {
            $statements_analyzer->remove_suppressed_issues(['PossiblyInvalidMethodCall']);
        }
        if (!in_array('MixedArrayOffset', $suppressed_issues, true)) {
            $statements_analyzer->remove_suppressed_issues(['MixedArrayOffset']);
        }
        $return_type = $statements_analyzer->node_data->get_type($fake_call) ?? null;
        $statements_analyzer->node_data = $old_data_provider;
        return $return_type;
    }
    /**
     * @param non-empty-array<int, string> $mapping_function_ids
     * @param list<PhpParser\Node\Arg> $array_args
     * @param int|null $fake_var_discriminator Set the fake variable id to a known value with the discriminator
     *                                         as a substring, and don't clear it from the context.
     * @param array<string, array<array<int, Assertion>>>|null $assertions
     */
    public static function get_return_type_from_mapping_ids(Statements_Analyzer $statements_source, array $mapping_function_ids, Context $context, Php_Parser\Node\Arg $function_call_arg, array $array_args, ?array &$assertions = null, ?int $fake_var_discriminator = null): Union
    {
        $mapping_return_type = null;
        $codebase = $statements_source->get_codebase();
        $clean_context = false;
        foreach ($mapping_function_ids as $mapping_function_id) {
            $mapping_function_id_parts = explode('&', $mapping_function_id);
            if ($fake_var_discriminator === null) {
                $fake_var_discriminator = mt_rand();
                $clean_context = true;
            }
            foreach ($mapping_function_id_parts as $mapping_function_id_part) {
                $fake_args = [];
                foreach ($array_args as $array_arg) {
                    $fake_args[] = new Virtual_Arg(new Virtual_Array_Dim_Fetch($array_arg->value, new Virtual_Variable("__fake_{$fake_var_discriminator}_offset_var__", $array_arg->value->get_attributes()), $array_arg->value->get_attributes()), false, false, $array_arg->get_attributes());
                }
                if (str_contains($mapping_function_id_part, '::')) {
                    $is_instance = false;
                    if ($mapping_function_id_part[0] === '$') {
                        $mapping_function_id_part = substr($mapping_function_id_part, 1);
                        $is_instance = true;
                    }
                    $method_id_parts = explode('::', $mapping_function_id_part);
                    [$callable_fq_class_name, $callable_method_name] = $method_id_parts;
                    if ($is_instance) {
                        $fake_method_call = new Virtual_Method_Call(new Virtual_Variable("__fake_{$fake_var_discriminator}_method_call_var__", $function_call_arg->get_attributes()), new Virtual_Identifier($callable_method_name, $function_call_arg->get_attributes()), $fake_args, $function_call_arg->get_attributes());
                        $lhs_instance_type = null;
                        $callable_type = $statements_source->node_data->get_type($function_call_arg->value);
                        if ($callable_type) {
                            foreach ($callable_type->get_atomic_types() as $atomic_type) {
                                if ($atomic_type instanceof T_Keyed_Array && count($atomic_type->properties) === 2 && isset($atomic_type->properties[0])) {
                                    $lhs_instance_type = $atomic_type->properties[0];
                                }
                            }
                        }
                        $context->vars_in_scope["\$__fake_{$fake_var_discriminator}_offset_var__"] = Type::get_mixed();
                        $context->vars_in_scope["\$__fake_{$fake_var_discriminator}_method_call_var__"] = $lhs_instance_type ?: new Union([new T_Named_Object($callable_fq_class_name)]);
                    } else {
                        $fake_method_call = new Virtual_Static_Call(new Virtual_Fully_Qualified($callable_fq_class_name, $function_call_arg->get_attributes()), new Virtual_Identifier($callable_method_name, $function_call_arg->get_attributes()), $fake_args, $function_call_arg->get_attributes());
                        $context->vars_in_scope["\$__fake_{$fake_var_discriminator}_offset_var__"] = Type::get_mixed();
                    }
                    $fake_method_return_type = self::execute_fake_call($statements_source, $fake_method_call, $context, $assertions);
                    $function_id_return_type = $fake_method_return_type ?? Type::get_mixed();
                } else {
                    $fake_function_call = new Virtual_Func_Call(new Virtual_Fully_Qualified($mapping_function_id_part, $function_call_arg->get_attributes()), $fake_args, $function_call_arg->get_attributes());
                    $context->vars_in_scope["\$__fake_{$fake_var_discriminator}_offset_var__"] = Type::get_mixed();
                    $fake_function_return_type = self::execute_fake_call($statements_source, $fake_function_call, $context, $assertions);
                    $function_id_return_type = $fake_function_return_type ?? Type::get_mixed();
                }
            }
            if ($clean_context) {
                self::clean_context($context, $fake_var_discriminator);
            }
            $fake_var_discriminator = null;
            $mapping_return_type = Type::combine_union_types($function_id_return_type, $mapping_return_type, $codebase);
        }
        return $mapping_return_type;
    }
    public static function clean_context(Context $context, int $fake_var_discriminator): void
    {
        foreach ($context->vars_in_scope as $var_in_scope => $_) {
            if (str_contains($var_in_scope, "__fake_{$fake_var_discriminator}_")) {
                unset($context->vars_in_scope[$var_in_scope]);
            }
        }
    }
}