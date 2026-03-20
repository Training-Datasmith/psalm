<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider\Params_Provider;

use Override;
use Php_Parser\Node\Expr\Const_Fetch;
use Psalm\Internal\Analyzer\Statements\Expression\Call\Arguments_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Expression_Identifier;
use Psalm\Internal\Analyzer\Statements\Expression\Fetch\Const_Fetch_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Simple_Type_Inferer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Issue\Invalid_Argument;
use Psalm\Issue\Possibly_Invalid_Argument;
use Psalm\Issue_Buffer;
use Psalm\Plugin\Event_Handler\Event\Function_Params_Provider_Event;
use Psalm\Plugin\Event_Handler\Function_Params_Provider_Interface;
use Psalm\Storage\Function_Like_Parameter;
use Psalm\Type;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Callable;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Union;
use function strtolower;
use const ARRAY_FILTER_USE_BOTH;
use const ARRAY_FILTER_USE_KEY;
/**
 * @internal
 */
final class Array_Filter_Params_Provider implements Function_Params_Provider_Interface
{
    /**
     * @return array<lowercase-string>
     */
    #[Override]
    public static function get_function_ids(): array
    {
        return Arguments_Analyzer::ARRAY_FILTERLIKE;
    }
    /**
     * @return ?list<FunctionLikeParameter>
     */
    #[Override]
    public static function get_function_params(Function_Params_Provider_Event $event): ?array
    {
        $call_args = $event->get_call_args();
        if (!isset($call_args[0]) || !isset($call_args[1])) {
            return null;
        }
        $statements_source = $event->get_statements_source();
        if (!$statements_source instanceof Statements_Analyzer) {
            // this is practically impossible
            // but the type in the caller is parent type StatementsSource
            // even though all callers provide StatementsAnalyzer
            return null;
        }
        $function_id = $event->get_function_id();
        $code_location = $event->get_code_location();
        if (isset($call_args[2])) {
            if ($function_id !== 'array_filter') {
                if ($code_location) {
                    Issue_Buffer::maybe_add(new Invalid_Argument("{$function_id} only takes two arguments", $code_location, $function_id), $statements_source->get_suppressed_issues());
                }
                return null;
            }
            if ($call_args[1]->value instanceof Const_Fetch && strtolower($call_args[1]->value->name->to_string()) === 'null') {
                if ($code_location) {
                    // using e.g. ARRAY_FILTER_USE_KEY as 3rd arg won't have any effect if the 2nd arg is null
                    // as it will still filter on the values
                    Issue_Buffer::maybe_add(new Invalid_Argument('The 3rd argument of array_filter is not used, when the 2nd argument is null', $code_location, 'array_filter'), $statements_source->get_suppressed_issues());
                }
                return null;
            }
        }
        // currently only supports literal types and variables (but not function calls)
        // due to https://github.com/vimeo/psalm/issues/8905
        $first_arg_type = Simple_Type_Inferer::infer($statements_source->get_codebase(), $statements_source->node_data, $call_args[0]->value, $statements_source->get_aliases(), $statements_source);
        if (!$first_arg_type) {
            $extended_var_id = Expression_Identifier::get_extended_var_id($call_args[0]->value, null, $statements_source) ?? '';
            $first_arg_type = $event->get_context()->vars_in_scope[$extended_var_id] ?? null;
        }
        $fallback = Type::get_array_atomic();
        if (!$first_arg_type || $first_arg_type->is_mixed()) {
            $first_arg_array = $fallback;
        } else {
            $first_arg_array = $first_arg_type->has_type('array') && ($array_atomic_type = $first_arg_type->get_array()) && ($array_atomic_type instanceof T_Array || $array_atomic_type instanceof T_Keyed_Array) ? $array_atomic_type : $fallback;
        }
        if ($first_arg_array instanceof T_Array) {
            $inner_type = $first_arg_array->type_params[1];
            $key_type = $first_arg_array->type_params[0];
        } else {
            $inner_type = $first_arg_array->get_generic_value_type();
            $key_type = $first_arg_array->get_generic_key_type();
        }
        $has_both = false;
        if (isset($call_args[2])) {
            $mode_type = Simple_Type_Inferer::infer($statements_source->get_codebase(), $statements_source->node_data, $call_args[2]->value, $statements_source->get_aliases(), $statements_source);
            if (!$mode_type && $call_args[2]->value instanceof Const_Fetch) {
                $mode_type = Const_Fetch_Analyzer::get_const_type($statements_source, $call_args[2]->value->name->to_string(), true, $event->get_context());
            } elseif (!$mode_type) {
                $extended_var_id = Expression_Identifier::get_extended_var_id($call_args[2]->value, null, $statements_source);
                if ($extended_var_id === null) {
                    return null;
                }
                $mode_type = $event->get_context()->vars_in_scope[$extended_var_id] ?? null;
            }
            if (!$mode_type || !$mode_type->all_int_literals()) {
                // if we have multiple possible types, keep the default args
                return null;
            }
            if ($mode_type->is_single_int_literal()) {
                $mode = $mode_type->get_single_int_literal()->value;
            } else {
                $mode = 0;
                foreach ($mode_type->get_literal_ints() as $atomic) {
                    if ($atomic->value === ARRAY_FILTER_USE_BOTH) {
                        // we have one which uses both keys and values and one that uses only keys/values
                        $has_both = true;
                        continue;
                    }
                    if ($atomic->value === ARRAY_FILTER_USE_KEY) {
                        // if one of them is ARRAY_FILTER_USE_KEY, all the other types will behave like mode 0
                        $inner_type = Type::combine_union_types($inner_type, $key_type, $statements_source->get_codebase());
                        continue;
                    }
                    // to report an error later on
                    if ($mode === 0 && $atomic->value !== 0) {
                        $mode = $atomic->value;
                    }
                }
            }
            if ($mode > ARRAY_FILTER_USE_KEY || $mode < 0) {
                if ($code_location) {
                    Issue_Buffer::maybe_add(new Possibly_Invalid_Argument('The provided 3rd argument of array_filter contains a value of ' . $mode . ', which will behave like 0 and filter on values only', $code_location, 'array_filter'), $statements_source->get_suppressed_issues());
                }
                $mode = 0;
            }
        } else {
            $mode = $function_id === 'array_filter' ? 0 : ARRAY_FILTER_USE_BOTH;
        }
        $callback_arg_value = new Function_Like_Parameter('value', false, $inner_type, null, null, null, false);
        $callback_arg_key = new Function_Like_Parameter('key', false, $key_type, null, null, null, false);
        if ($mode === ARRAY_FILTER_USE_BOTH) {
            $callback_arg = [$callback_arg_value, $callback_arg_key];
        } elseif ($mode === ARRAY_FILTER_USE_KEY) {
            $callback_arg = [$callback_arg_key];
        } elseif ($has_both) {
            // if we have both + other flags, the 2nd arg is optional
            $callback_arg_key->is_optional = true;
            $callback_arg = [$callback_arg_value, $callback_arg_key];
        } else {
            $callback_arg = [$callback_arg_value];
        }
        $callable = new T_Callable('callable', $callback_arg, Type::get_mixed());
        return [new Function_Like_Parameter('array', false, Type::get_array(), Type::get_array(), null, null, false), new Function_Like_Parameter('callback', false, new Union([$callable])), new Function_Like_Parameter('mode', false, Type::get_int(), Type::get_int())];
    }
}