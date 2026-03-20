<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider\Return_Type_Provider;

use Override;
use Php_Parser;
use Psalm\Code_Location;
use Psalm\Internal\Analyzer\Statements\Expression\Call_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Codebase\Internal_Call_Map_Handler;
use Psalm\Internal\Method_Identifier;
use Psalm\Internal\Type\Comparator\Union_Type_Comparator;
use Psalm\Issue\Invalid_Argument;
use Psalm\Issue_Buffer;
use Psalm\Plugin\Event_Handler\Event\Function_Return_Type_Provider_Event;
use Psalm\Plugin\Event_Handler\Function_Return_Type_Provider_Interface;
use Psalm\Type;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Atomic\T_Null;
use Psalm\Type\Union;
use function count;
use function explode;
use function in_array;
use function reset;
use function str_contains;
use function strtolower;
use function substr;
/**
 * @internal
 */
final class Array_Reduce_Return_Type_Provider implements Function_Return_Type_Provider_Interface
{
    /**
     * @return array<lowercase-string>
     */
    #[Override]
    public static function get_function_ids(): array
    {
        return ['array_reduce'];
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
        if (!isset($call_args[0]) || !isset($call_args[1])) {
            return Type::get_mixed();
        }
        $codebase = $statements_source->get_codebase();
        $array_arg = $call_args[0]->value;
        $function_call_arg = $call_args[1]->value;
        $array_arg_type = $statements_source->node_data->get_type($array_arg);
        $function_call_arg_type = $statements_source->node_data->get_type($function_call_arg);
        if (!$array_arg_type || !$function_call_arg_type) {
            return Type::get_mixed();
        }
        $array_arg_types = $array_arg_type->get_atomic_types();
        $array_arg_atomic_type = null;
        if (isset($array_arg_types['array']) && ($array_arg_types['array'] instanceof T_Array || $array_arg_types['array'] instanceof T_Keyed_Array)) {
            $array_arg_atomic_type = $array_arg_types['array'];
            if ($array_arg_atomic_type instanceof T_Keyed_Array) {
                $array_arg_atomic_type = $array_arg_atomic_type->get_generic_array_type();
            }
        }
        if (!isset($call_args[2])) {
            $reduce_return_type = new Union([new T_Null()], ['ignore_nullable_issues' => true]);
        } else {
            $reduce_return_type = $statements_source->node_data->get_type($call_args[2]->value);
            if (!$reduce_return_type) {
                return Type::get_mixed();
            }
            if ($reduce_return_type->has_mixed()) {
                return Type::get_mixed();
            }
        }
        $initial_type = $reduce_return_type;
        $closure_types = $function_call_arg_type->get_closure_types() ?: $function_call_arg_type->get_callable_types();
        if ($closure_types) {
            $closure_atomic_type = reset($closure_types);
            $closure_return_type = $closure_atomic_type->return_type ?: Type::get_mixed();
            if ($closure_return_type->is_void()) {
                $closure_return_type = Type::get_null();
            }
            $reduce_return_type = Type::combine_union_types($closure_return_type, $reduce_return_type);
            if ($closure_atomic_type->params !== null) {
                if (count($closure_atomic_type->params) < 1) {
                    Issue_Buffer::maybe_add(new Invalid_Argument('The closure passed to array_reduce at least one parameter', new Code_Location($statements_source, $function_call_arg)), $statements_source->get_suppressed_issues());
                    return Type::get_mixed();
                }
                $carry_param = $closure_atomic_type->params[0];
                $item_param = $closure_atomic_type->params[1] ?? null;
                if ($carry_param->type && (!Union_Type_Comparator::is_contained_by($codebase, $initial_type, $carry_param->type) || !$reduce_return_type->has_mixed() && !Union_Type_Comparator::is_contained_by($codebase, $reduce_return_type, $carry_param->type))) {
                    Issue_Buffer::maybe_add(new Invalid_Argument('The first param of the closure passed to array_reduce must take ' . $reduce_return_type . ' but only accepts ' . $carry_param->type, $carry_param->type_location ?: new Code_Location($statements_source, $function_call_arg)), $statements_source->get_suppressed_issues());
                    return Type::get_mixed();
                }
                if ($item_param && $item_param->type && $array_arg_atomic_type && !$array_arg_atomic_type->type_params[1]->has_mixed() && !Union_Type_Comparator::is_contained_by($codebase, $array_arg_atomic_type->type_params[1], $item_param->type)) {
                    Issue_Buffer::maybe_add(new Invalid_Argument('The second param of the closure passed to array_reduce must take ' . $array_arg_atomic_type->type_params[1] . ' but only accepts ' . $item_param->type, $item_param->type_location ?: new Code_Location($statements_source, $function_call_arg)), $statements_source->get_suppressed_issues());
                    return Type::get_mixed();
                }
            }
            return $reduce_return_type;
        }
        if ($function_call_arg instanceof Php_Parser\Node\Scalar\String_ || $function_call_arg instanceof Php_Parser\Node\Expr\Array_ || $function_call_arg instanceof Php_Parser\Node\Expr\Binary_Op\Concat) {
            $mapping_function_ids = Call_Analyzer::get_function_ids_from_callable_arg($statements_source, $function_call_arg);
            $call_map = Internal_Call_Map_Handler::get_call_map();
            foreach ($mapping_function_ids as $mapping_function_id) {
                $mapping_function_id_parts = explode('&', $mapping_function_id);
                $part_match_found = false;
                foreach ($mapping_function_id_parts as $mapping_function_id_part) {
                    if (isset($call_map[$mapping_function_id_part][0])) {
                        if ($call_map[$mapping_function_id_part][0]) {
                            $mapped_function_return = Type::parse_string($call_map[$mapping_function_id_part][0]);
                            $reduce_return_type = Type::combine_union_types($reduce_return_type, $mapped_function_return);
                            $part_match_found = true;
                        }
                    } elseif ($mapping_function_id_part) {
                        if (str_contains($mapping_function_id_part, '::')) {
                            if ($mapping_function_id_part[0] === '$') {
                                $mapping_function_id_part = substr($mapping_function_id_part, 1);
                            }
                            [$callable_fq_class_name, $method_name] = explode('::', $mapping_function_id_part);
                            if (in_array($callable_fq_class_name, ['self', 'static'], true)) {
                                $callable_fq_class_name = $statements_source->get_fqcln();
                                if ($callable_fq_class_name === null) {
                                    continue;
                                }
                            }
                            if ($callable_fq_class_name === 'parent') {
                                continue;
                            }
                            $method_id = new Method_Identifier($callable_fq_class_name, strtolower($method_name));
                            if (!$codebase->methods->method_exists($method_id, !$context->collect_initializations && !$context->collect_mutations ? $context->calling_method_id : null, $codebase->collect_locations ? new Code_Location($statements_source, $function_call_arg) : null, null, $statements_source->get_file_path())) {
                                continue;
                            }
                            $part_match_found = true;
                            $self_class = 'self';
                            $return_type = $codebase->methods->get_method_return_type($method_id, $self_class) ?? Type::get_mixed();
                        } else {
                            if (!$codebase->functions->function_exists($statements_source, strtolower($mapping_function_id_part))) {
                                return Type::get_mixed();
                            }
                            $part_match_found = true;
                            $function_storage = $codebase->functions->get_storage($statements_source, strtolower($mapping_function_id_part));
                            $return_type = $function_storage->return_type ?: Type::get_mixed();
                        }
                        $reduce_return_type = Type::combine_union_types($reduce_return_type, $return_type);
                    }
                }
                if ($part_match_found === false) {
                    return Type::get_mixed();
                }
            }
            return $reduce_return_type;
        }
        return Type::get_mixed();
    }
}