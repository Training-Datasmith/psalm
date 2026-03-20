<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider\Params_Provider;

use Override;
use Php_Parser\Node\Expr\Const_Fetch;
use Php_Parser\Node\Expr\Func_Call;
use Php_Parser\Node\Expr\Method_Call;
use Psalm\Internal\Analyzer\Statements\Expression\Expression_Identifier;
use Psalm\Internal\Analyzer\Statements\Expression\Fetch\Const_Fetch_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Simple_Type_Inferer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Issue\Invalid_Argument;
use Psalm\Issue_Buffer;
use Psalm\Plugin\Event_Handler\Event\Function_Params_Provider_Event;
use Psalm\Plugin\Event_Handler\Function_Params_Provider_Interface;
use Psalm\Storage\Function_Like_Parameter;
use Psalm\Type;
use function in_array;
use const SORT_ASC;
use const SORT_DESC;
use const SORT_FLAG_CASE;
use const SORT_LOCALE_STRING;
use const SORT_NATURAL;
use const SORT_NUMERIC;
use const SORT_REGULAR;
use const SORT_STRING;
/**
 * @internal
 */
final class Array_Multisort_Params_Provider implements Function_Params_Provider_Interface
{
    /**
     * @return array<lowercase-string>
     */
    #[Override]
    public static function get_function_ids(): array
    {
        return ['array_multisort'];
    }
    /**
     * @return ?list<FunctionLikeParameter>
     */
    #[Override]
    public static function get_function_params(Function_Params_Provider_Event $event): ?array
    {
        $call_args = $event->get_call_args();
        if (!isset($call_args[0])) {
            return null;
        }
        $statements_source = $event->get_statements_source();
        if (!$statements_source instanceof Statements_Analyzer) {
            // this is practically impossible
            // but the type in the caller is parent type StatementsSource
            // even though all callers provide StatementsAnalyzer
            return null;
        }
        $code_location = $event->get_code_location();
        $params = [];
        $previous_param = false;
        $last_array_index = 0;
        $last_by_ref_index = -1;
        $first_non_ref_index_after_by_ref = -1;
        foreach ($call_args as $key => $call_arg) {
            $param_type = Simple_Type_Inferer::infer($statements_source->get_codebase(), $statements_source->node_data, $call_arg->value, $statements_source->get_aliases(), $statements_source);
            if (!$param_type && $call_arg->value instanceof Const_Fetch) {
                $param_type = Const_Fetch_Analyzer::get_const_type($statements_source, $call_arg->value->name->to_string(), true, $event->get_context());
            }
            // @todo currently assumes any function calls are for array types not for sort order/flags
            // actually need to check the return type
            // which isn't possible atm due to https://github.com/vimeo/psalm/issues/8905
            if (!$param_type && ($call_arg->value instanceof Func_Call || $call_arg->value instanceof Method_Call)) {
                if ($first_non_ref_index_after_by_ref < $last_by_ref_index) {
                    $first_non_ref_index_after_by_ref = $key;
                }
                $last_array_index = $key;
                $previous_param = 'array';
                $params[] = new Function_Like_Parameter(
                    'array' . ($last_array_index + 1),
                    // function calls will not be used by reference
                    false,
                    Type::get_array(),
                    $key === 0 ? Type::get_array() : null
                );
                continue;
            }
            $extended_var_id = null;
            if (!$param_type) {
                $extended_var_id = Expression_Identifier::get_extended_var_id($call_arg->value, null, $statements_source);
                if ($extended_var_id === null) {
                    return null;
                }
                $param_type = $event->get_context()->vars_in_scope[$extended_var_id] ?? null;
            }
            if (!$param_type) {
                return null;
            }
            if ($key === 0 && !$param_type->is_array()) {
                return null;
            }
            if ($param_type->is_array() && $extended_var_id) {
                $last_by_ref_index = $key;
                $last_array_index = $key;
                $previous_param = 'array';
                $params[] = new Function_Like_Parameter('array' . ($last_array_index + 1), true, $param_type, $key === 0 ? Type::get_array() : null);
                continue;
            }
            if ($param_type->all_int_literals()) {
                $sort_order = [SORT_ASC, SORT_DESC];
                $sort_flags = [SORT_REGULAR, SORT_NUMERIC, SORT_STRING, SORT_LOCALE_STRING, SORT_NATURAL, SORT_STRING | SORT_FLAG_CASE, SORT_NATURAL | SORT_FLAG_CASE];
                $sort_param = false;
                foreach ($param_type->get_literal_ints() as $atomic) {
                    if (in_array($atomic->value, $sort_order, true)) {
                        if ($sort_param === 'sort_order_flags') {
                            continue;
                        }
                        if ($sort_param === 'sort_order') {
                            continue;
                        }
                        if ($sort_param === 'sort_flags') {
                            $sort_param = 'sort_order_flags';
                            continue;
                        }
                        $sort_param = 'sort_order';
                        continue;
                    }
                    if (in_array($atomic->value, $sort_flags, true)) {
                        if ($sort_param === 'sort_order_flags') {
                            continue;
                        }
                        if ($sort_param === 'sort_flags') {
                            continue;
                        }
                        if ($sort_param === 'sort_order') {
                            $sort_param = 'sort_order_flags';
                            continue;
                        }
                        $sort_param = 'sort_flags';
                        continue;
                    }
                    if ($code_location) {
                        Issue_Buffer::maybe_add(new Invalid_Argument('Argument ' . ($key + 1) . ' of array_multisort sort order/flag contains an invalid value of ' . $atomic->value, $code_location, 'array_multisort'), $statements_source->get_suppressed_issues());
                    }
                }
                if ($sort_param === false) {
                    return null;
                }
                if (($sort_param === 'sort_order' || $sort_param === 'sort_order_flags') && $previous_param !== 'array') {
                    if ($code_location) {
                        Issue_Buffer::maybe_add(new Invalid_Argument('Argument ' . ($key + 1) . ' of array_multisort contains sort order flags' . ' and can only be used after an array parameter', $code_location, 'array_multisort'), $statements_source->get_suppressed_issues());
                    }
                    return null;
                }
                if ($sort_param === 'sort_flags' && $previous_param !== 'array' && $previous_param !== 'sort_order') {
                    if ($code_location) {
                        Issue_Buffer::maybe_add(new Invalid_Argument('Argument ' . ($key + 1) . ' of array_multisort are sort flags' . ' and cannot be used after a parameter with sort flags', $code_location, 'array_multisort'), $statements_source->get_suppressed_issues());
                    }
                    return null;
                }
                if ($sort_param === 'sort_order_flags') {
                    $previous_param = 'sort_order';
                } else {
                    $previous_param = $sort_param;
                }
                $params[] = new Function_Like_Parameter('array' . ($last_array_index + 1) . '_' . $previous_param, false, Type::get_int());
                continue;
            }
            if (!$param_type->is_array()) {
                // too complex for now
                return null;
            }
            if ($first_non_ref_index_after_by_ref < $last_by_ref_index) {
                $first_non_ref_index_after_by_ref = $key;
            }
            $last_array_index = $key;
            $previous_param = 'array';
            $params[] = new Function_Like_Parameter('array' . ($last_array_index + 1), false, Type::get_array());
        }
        if ($code_location) {
            if ($last_by_ref_index === -1) {
                Issue_Buffer::maybe_add(new Invalid_Argument('At least 1 array argument of array_multisort must be a variable,' . ' since the sorting happens by reference and otherwise this function call does nothing', $code_location, 'array_multisort'), $statements_source->get_suppressed_issues());
            } elseif ($first_non_ref_index_after_by_ref > $last_by_ref_index) {
                Issue_Buffer::maybe_add(new Invalid_Argument('All arguments of array_multisort after argument ' . $first_non_ref_index_after_by_ref . ', which are after the last by reference passed array argument and its flags,' . ' are redundant and can be removed, since the sorting happens by reference', $code_location, 'array_multisort'), $statements_source->get_suppressed_issues());
            }
        }
        return $params;
    }
}