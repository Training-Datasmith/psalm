<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider\Return_Type_Provider;

use Override;
use Php_Parser;
use Psalm\Code_Location;
use Psalm\Exception\Complicated_Expression_Exception;
use Psalm\Internal\Algebra;
use Psalm\Internal\Algebra\Formula_Generator;
use Psalm\Internal\Analyzer\Statements\Expression\Call_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Expression_Identifier;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Type\Assertion_Reconciler;
use Psalm\Issue\Invalid_Return_Type;
use Psalm\Issue_Buffer;
use Psalm\Plugin\Event_Handler\Event\Function_Return_Type_Provider_Event;
use Psalm\Plugin\Event_Handler\Function_Return_Type_Provider_Interface;
use Psalm\Storage\Assertion\Truthy;
use Psalm\Type;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Int;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Atomic\T_String;
use Psalm\Type\Reconciler;
use Psalm\Type\Union;
use function array_filter;
use function array_map;
use function array_slice;
use function count;
use function is_string;
use function mt_rand;
use function reset;
use function spl_object_id;
/**
 * @internal
 */
final class Array_Filter_Return_Type_Provider implements Function_Return_Type_Provider_Interface
{
    /**
     * @return array<lowercase-string>
     */
    #[Override]
    public static function get_function_ids(): array
    {
        return ['array_filter'];
    }
    #[Override]
    public static function get_function_return_type(Function_Return_Type_Provider_Event $event): Union
    {
        $statements_source = $event->get_statements_source();
        $call_args = $event->get_call_args();
        $context = $event->get_context();
        $code_location = $event->get_code_location();
        if (!$statements_source instanceof Statements_Analyzer || !$call_args) {
            return Type::get_mixed();
        }
        $fallback = Type::get_array_atomic();
        $array_arg = $call_args[0]->value ?? null;
        if (!$array_arg) {
            $first_arg_array = $fallback;
        } else {
            $first_arg_type = $statements_source->node_data->get_type($array_arg);
            if (!$first_arg_type || $first_arg_type->is_mixed()) {
                $first_arg_array = $fallback;
            } else {
                $first_arg_array = $first_arg_type->has_type('array') && ($array_atomic_type = $first_arg_type->get_array()) && ($array_atomic_type instanceof T_Array || $array_atomic_type instanceof T_Keyed_Array) ? $array_atomic_type : $fallback;
            }
        }
        if ($first_arg_array instanceof T_Array) {
            $inner_type = $first_arg_array->type_params[1];
            $key_type = $first_arg_array->type_params[0];
        } else {
            $inner_type = $first_arg_array->get_generic_value_type();
            $key_type = $first_arg_array->get_generic_key_type();
            if (!isset($call_args[1]) && $first_arg_array->fallback_params === null) {
                $had_one = count($first_arg_array->properties) === 1;
                $new_properties = array_filter(array_map(static function ($keyed_type) use ($statements_source, $context): \Psalm\Type\Union {
                    $prev_keyed_type = $keyed_type;
                    $keyed_type = Assertion_Reconciler::reconcile(new Truthy(), $keyed_type, '', $statements_source, $context->inside_loop, [], null, $statements_source->get_suppressed_issues());
                    return $keyed_type->set_possibly_undefined(!$prev_keyed_type->is_always_truthy());
                }, $first_arg_array->properties), static fn($keyed_type): bool => !$keyed_type->is_never());
                if (!$new_properties) {
                    return Type::get_empty_array();
                }
                return new Union([new T_Keyed_Array($new_properties, null, $first_arg_array->fallback_params, $first_arg_array->is_list && $had_one)]);
            }
        }
        if (!isset($call_args[1])) {
            $inner_type = Assertion_Reconciler::reconcile(new Truthy(), $inner_type, '', $statements_source, $context->inside_loop, [], null, $statements_source->get_suppressed_issues());
            if ($first_arg_array instanceof T_Keyed_Array && $first_arg_array->is_list && $key_type->is_single_int_literal() && $key_type->get_single_int_literal()->value === 0) {
                return Type::get_list($inner_type);
            }
            if ($key_type->get_literal_strings()) {
                $key_type = $key_type->get_builder()->add_type(new T_String())->freeze();
            }
            if ($key_type->get_literal_ints()) {
                $key_type = $key_type->get_builder()->add_type(new T_Int())->freeze();
            }
            if ($inner_type->is_union_empty()) {
                return Type::get_empty_array();
            }
            return new Union([new T_Array([$key_type, $inner_type])]);
        }
        if (!isset($call_args[2])) {
            $function_call_arg = $call_args[1];
            $callable_extended_var_id = Expression_Identifier::get_extended_var_id($function_call_arg->value, null, $statements_source);
            $mapping_function_ids = [];
            if ($callable_extended_var_id) {
                $possibly_function_ids = $context->vars_in_scope[$callable_extended_var_id] ?? null;
                // @todo for array callables
                if ($possibly_function_ids && $possibly_function_ids->all_string_literals()) {
                    foreach ($possibly_function_ids->get_literal_strings() as $atomic) {
                        $mapping_function_ids[] = $atomic->value;
                    }
                }
            }
            if ($function_call_arg->value instanceof Php_Parser\Node\Scalar\String_ || $function_call_arg->value instanceof Php_Parser\Node\Expr\Array_ || $function_call_arg->value instanceof Php_Parser\Node\Expr\Binary_Op\Concat || $mapping_function_ids !== []) {
                if ($mapping_function_ids === []) {
                    $mapping_function_ids = Call_Analyzer::get_function_ids_from_callable_arg($statements_source, $function_call_arg->value);
                }
                if ($array_arg && $mapping_function_ids) {
                    $assertions = [];
                    $fake_var_discriminator = mt_rand();
                    Array_Map_Return_Type_Provider::get_return_type_from_mapping_ids($statements_source, $mapping_function_ids, $context, $function_call_arg, array_slice($call_args, 0, 1), $assertions, $fake_var_discriminator);
                    $extended_var_id = Expression_Identifier::get_extended_var_id($array_arg, null, $statements_source);
                    $assertion_id = $extended_var_id . "[\$__fake_{$fake_var_discriminator}_offset_var__]";
                    if (isset($assertions[$assertion_id])) {
                        $changed_var_ids = [];
                        $assertions = ['$inner_type' => $assertions[$assertion_id]];
                        [$reconciled_types, $_] = Reconciler::reconcile_keyed_types($assertions, $assertions, ['$inner_type' => $inner_type], [], $changed_var_ids, ['$inner_type' => true], $statements_source, $statements_source->get_template_type_map() ?: [], false, new Code_Location($statements_source, $function_call_arg->value));
                        if (isset($reconciled_types['$inner_type'])) {
                            $inner_type = $reconciled_types['$inner_type'];
                        }
                    }
                    Array_Map_Return_Type_Provider::clean_context($context, $fake_var_discriminator);
                }
            } elseif (($function_call_arg->value instanceof Php_Parser\Node\Expr\Closure || $function_call_arg->value instanceof Php_Parser\Node\Expr\Arrow_Function) && ($second_arg_type = $statements_source->node_data->get_type($function_call_arg->value)) && $closure_types = $second_arg_type->get_closure_types()) {
                $closure_atomic_type = reset($closure_types);
                $closure_return_type = $closure_atomic_type->return_type ?: Type::get_mixed();
                if ($closure_return_type->is_void()) {
                    Issue_Buffer::maybe_add(new Invalid_Return_Type('No return type could be found in the closure passed to array_filter', $code_location), $statements_source->get_suppressed_issues());
                    return Type::get_array();
                }
                /** @var list<PhpParser\Node\Stmt> */
                $function_call_stmts = $function_call_arg->value->get_stmts();
                if (count($function_call_stmts) === 1 && count($function_call_arg->value->params)) {
                    $first_param = $function_call_arg->value->params[0];
                    $stmt = $function_call_stmts[0];
                    if ($first_param->variadic === false && $first_param->var instanceof Php_Parser\Node\Expr\Variable && is_string($first_param->var->name) && $stmt instanceof Php_Parser\Node\Stmt\Return_ && $stmt->expr) {
                        $codebase = $statements_source->get_codebase();
                        $cond_object_id = spl_object_id($stmt->expr);
                        try {
                            $filter_clauses = Formula_Generator::get_formula($cond_object_id, $cond_object_id, $stmt->expr, $context->self, $statements_source, $codebase);
                        } catch (Complicated_Expression_Exception) {
                            $filter_clauses = [];
                        }
                        $assertions = Algebra::get_truths_from_formula($filter_clauses, $cond_object_id);
                        if (isset($assertions['$' . $first_param->var->name])) {
                            $changed_var_ids = [];
                            $assertions = ['$inner_type' => $assertions['$' . $first_param->var->name]];
                            [$reconciled_types, $_] = Reconciler::reconcile_keyed_types($assertions, $assertions, ['$inner_type' => $inner_type], [], $changed_var_ids, ['$inner_type' => true], $statements_source, $statements_source->get_template_type_map() ?: [], false, new Code_Location($statements_source, $stmt));
                            if (isset($reconciled_types['$inner_type'])) {
                                $inner_type = $reconciled_types['$inner_type'];
                            }
                        }
                    }
                }
            }
            return new Union([new T_Array([$key_type, $inner_type])]);
        }
        if ($inner_type->is_union_empty()) {
            return Type::get_empty_array();
        }
        return new Union([new T_Array([$key_type, $inner_type])]);
    }
}