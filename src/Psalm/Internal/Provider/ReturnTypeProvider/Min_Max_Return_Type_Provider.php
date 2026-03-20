<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider\Return_Type_Provider;

use Override;
use Psalm\Internal\Type\Array_Type;
use Psalm\Plugin\Event_Handler\Event\Function_Return_Type_Provider_Event;
use Psalm\Plugin\Event_Handler\Function_Return_Type_Provider_Interface;
use Psalm\Type;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Int;
use Psalm\Type\Atomic\T_Int_Range;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Atomic\T_Literal_Int;
use Psalm\Type\Union;
use UnexpectedValueException;
use function array_filter;
use function assert;
use function count;
use function in_array;
use function max;
use function min;
/**
 * @internal
 */
final class Min_Max_Return_Type_Provider implements Function_Return_Type_Provider_Interface
{
    /**
     * @return array<lowercase-string>
     */
    #[Override]
    public static function get_function_ids(): array
    {
        return ['min', 'max'];
    }
    #[Override]
    public static function get_function_return_type(Function_Return_Type_Provider_Event $event): ?Union
    {
        $call_args = $event->get_call_args();
        if (count($call_args) === 0) {
            return null;
        }
        $statements_source = $event->get_statements_source();
        $node_type_provider = $statements_source->get_node_type_provider();
        if (count($call_args) === 1 && ($array_arg_type = $node_type_provider->get_type($call_args[0]->value)) && $array_arg_type->is_single() && $array_arg_type->has_array() && $array_type = Array_Type::infer($array_arg_type->get_single_atomic())) {
            return $array_type->value;
        }
        $all_int = true;
        $min_bounds = [];
        $max_bounds = [];
        foreach ($call_args as $arg) {
            if ($arg_type = $node_type_provider->get_type($arg->value)) {
                if ($arg->unpack) {
                    if (!$arg_type->is_single() || !$arg_type->is_array()) {
                        return Type::get_mixed();
                    }
                    $array_arg_type = $arg_type->get_array();
                    if ($array_arg_type instanceof T_Keyed_Array) {
                        $possibly_unpacked_arg_types = $array_arg_type->properties;
                    } else {
                        assert($array_arg_type instanceof T_Array);
                        $possibly_unpacked_arg_types = [$array_arg_type->type_params[1]];
                    }
                } else {
                    $possibly_unpacked_arg_types = [$arg_type];
                }
                foreach ($possibly_unpacked_arg_types as $possibly_unpacked_arg_type) {
                    foreach ($possibly_unpacked_arg_type->get_atomic_types() as $atomic_type) {
                        if (!$atomic_type instanceof T_Int) {
                            $all_int = false;
                            break 2;
                        }
                        if ($atomic_type instanceof T_Literal_Int) {
                            $min_bounds[] = $atomic_type->value;
                            $max_bounds[] = $atomic_type->value;
                        } elseif ($atomic_type instanceof T_Int_Range) {
                            $min_bounds[] = $atomic_type->min_bound;
                            $max_bounds[] = $atomic_type->max_bound;
                        } elseif ($atomic_type::class === T_Int::class) {
                            $min_bounds[] = null;
                            $max_bounds[] = null;
                        } else {
                            throw new UnexpectedValueException('Unexpected type');
                        }
                    }
                }
            } else {
                return Type::get_mixed();
            }
        }
        if ($all_int) {
            if ($event->get_function_id() === 'min') {
                assert(count($min_bounds) !== 0);
                //null values in $max_bounds doesn't make sense for min() so we remove them
                $max_bounds = array_filter($max_bounds, static fn(?int $v): bool => $v !== null) ?: [null];
                $min_potential_int = in_array(null, $min_bounds, true) ? null : min($min_bounds);
                $max_potential_int = in_array(null, $max_bounds, true) ? null : min($max_bounds);
            } else {
                assert(count($max_bounds) !== 0);
                //null values in $min_bounds doesn't make sense for max() so we remove them
                $min_bounds = array_filter($min_bounds, static fn(?int $v): bool => $v !== null) ?: [null];
                $min_potential_int = in_array(null, $min_bounds, true) ? null : max($min_bounds);
                $max_potential_int = in_array(null, $max_bounds, true) ? null : max($max_bounds);
            }
            if ($min_potential_int === null && $max_potential_int === null) {
                return Type::get_int();
            }
            if ($min_potential_int === $max_potential_int) {
                return Type::get_int(false, $min_potential_int);
            }
            return Type::get_int_range($min_potential_int, $max_potential_int);
        }
        //if we're dealing with non-int elements, just combine them all together
        $return_type = null;
        foreach ($call_args as $arg) {
            if ($arg_type = $node_type_provider->get_type($arg->value)) {
                if ($arg->unpack) {
                    if ($arg_type->is_single() && $arg_type->is_array()) {
                        $array_type = Array_Type::infer($arg_type->get_single_atomic());
                        assert($array_type !== null);
                        $additional_type = $array_type->value;
                    } else {
                        $additional_type = Type::get_mixed();
                    }
                } else {
                    $additional_type = $arg_type;
                }
                $return_type = Type::combine_union_types($return_type, $additional_type);
            } else {
                return Type::get_mixed();
            }
        }
        return $return_type;
    }
}