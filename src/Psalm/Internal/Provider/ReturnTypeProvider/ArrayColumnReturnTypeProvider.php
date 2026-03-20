<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider\Return_Type_Provider;

use Override;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Internal\Analyzer\Source_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Plugin\Event_Handler\Event\Function_Return_Type_Provider_Event;
use Psalm\Plugin\Event_Handler\Function_Return_Type_Provider_Interface;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Class_String_Map;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Atomic\T_Non_Empty_Array;
use Psalm\Type\Union;
use function count;
/**
 * @internal
 */
final class Array_Column_Return_Type_Provider implements Function_Return_Type_Provider_Interface
{
    /**
     * @return array<lowercase-string>
     */
    #[Override]
    public static function get_function_ids(): array
    {
        return ['array_column'];
    }
    #[Override]
    public static function get_function_return_type(Function_Return_Type_Provider_Event $event): Union
    {
        $statements_source = $event->get_statements_source();
        $call_args = $event->get_call_args();
        if (!$statements_source instanceof Statements_Analyzer || count($call_args) < 2) {
            return Type::get_mixed();
        }
        $context = $event->get_context();
        $code_location = $event->get_code_location();
        $value_column_name = null;
        $value_column_name_is_null = false;
        // calculate value column name
        if ($second_arg_type = $statements_source->node_data->get_type($call_args[1]->value)) {
            if ($second_arg_type->is_single_int_literal()) {
                $value_column_name = $second_arg_type->get_single_int_literal()->value;
            } elseif ($second_arg_type->is_single_string_literal()) {
                $value_column_name = $second_arg_type->get_single_string_literal()->value;
            }
            $value_column_name_is_null = $second_arg_type->is_null();
        }
        $key_column_name = null;
        $key_column_name_is_null = false;
        $third_arg_type = null;
        // calculate key column name
        if (isset($call_args[2])) {
            $third_arg_type = $statements_source->node_data->get_type($call_args[2]->value);
            if ($third_arg_type) {
                if ($third_arg_type->is_single_int_literal()) {
                    $key_column_name = $third_arg_type->get_single_int_literal()->value;
                } elseif ($third_arg_type->is_single_string_literal()) {
                    $key_column_name = $third_arg_type->get_single_string_literal()->value;
                }
                $key_column_name_is_null = $third_arg_type->is_null();
            }
        }
        $row_type = $row_shape = null;
        $input_array_not_empty = false;
        // calculate row shape
        if (($first_arg_type = $statements_source->node_data->get_type($call_args[0]->value)) && $first_arg_type->is_single() && $first_arg_type->has_array()) {
            $input_array = $first_arg_type->get_array();
            if ($input_array instanceof T_Keyed_Array && !$input_array->fallback_params && ($value_column_name !== null || $value_column_name_is_null) && !($third_arg_type && !$key_column_name)) {
                $properties = [];
                $ok = true;
                $last_custom_key = -1;
                $is_list = true;
                $had_possibly_undefined = false;
                // This incorrectly assumes that the array is sorted, may be problematic
                // Will be fixed when order is enforced
                $key = -1;
                foreach ($input_array->properties as $property) {
                    $row_shape = self::get_row_shape($property, $statements_source, $context, $code_location);
                    if (!$row_shape) {
                        continue;
                    }
                    if (!$row_shape instanceof T_Keyed_Array) {
                        if ($row_shape instanceof T_Array && $row_shape->is_empty_array()) {
                            continue;
                        }
                        $ok = false;
                        break;
                    }
                    if ($value_column_name !== null) {
                        if (isset($row_shape->properties[$value_column_name])) {
                            $result_element_type = $row_shape->properties[$value_column_name];
                        } elseif ($row_shape->fallback_params) {
                            $ok = false;
                            break;
                        } else {
                            continue;
                        }
                    } else {
                        $result_element_type = $property;
                    }
                    if ($key_column_name !== null) {
                        if (isset($row_shape->properties[$key_column_name])) {
                            $result_key_type = $row_shape->properties[$key_column_name];
                            if ($result_key_type->is_single_int_literal()) {
                                $key = $result_key_type->get_single_int_literal()->value;
                                if ($is_list && $last_custom_key != $key - 1) {
                                    $is_list = false;
                                }
                                $last_custom_key = $key;
                            } elseif ($result_key_type->is_single_string_literal()) {
                                $key = $result_key_type->get_single_string_literal()->value;
                                $is_list = false;
                            } else {
                                $ok = false;
                                break;
                            }
                        } else {
                            $ok = false;
                            break;
                        }
                    } else {
                        /** @psalm-suppress StringIncrement Actually always an int in this branch */
                        ++$key;
                    }
                    $properties[$key] = $result_element_type->set_possibly_undefined($property->possibly_undefined);
                    if (!$property->possibly_undefined && $had_possibly_undefined) {
                        $is_list = false;
                    }
                    $had_possibly_undefined = $had_possibly_undefined || $property->possibly_undefined;
                }
                if ($ok) {
                    if (!$properties) {
                        return Type::get_empty_array();
                    }
                    return new Union([new T_Keyed_Array($properties, null, $input_array->fallback_params, $is_list)]);
                }
            }
            if ($input_array instanceof T_Keyed_Array) {
                $row_type = $input_array->get_generic_value_type();
            } elseif ($input_array instanceof T_Array) {
                $row_type = $input_array->type_params[1];
            }
            $row_shape = self::get_row_shape($row_type, $statements_source, $context, $code_location);
            $input_array_not_empty = $input_array instanceof T_Non_Empty_Array || $input_array instanceof T_Keyed_Array && $input_array->is_non_empty();
        }
        $result_key_type = Type::get_array_key();
        $result_element_type = null !== $row_type && $value_column_name_is_null ? $row_type : null;
        $have_at_least_one_res = false;
        // calculate results
        if ($row_shape instanceof T_Keyed_Array) {
            if (null !== $value_column_name && isset($row_shape->properties[$value_column_name])) {
                $result_element_type = $row_shape->properties[$value_column_name];
                // When the selected key is possibly_undefined, the resulting array can be empty
                if ($input_array_not_empty && $result_element_type->possibly_undefined !== true) {
                    $have_at_least_one_res = true;
                }
                //array_column skips undefined elements so resulting type is necessarily defined
                $result_element_type = $result_element_type->set_possibly_undefined(false);
            } elseif (!$value_column_name_is_null) {
                $result_element_type = Type::get_mixed();
            }
            if (null !== $key_column_name && isset($row_shape->properties[$key_column_name])) {
                $result_key_type = $row_shape->properties[$key_column_name];
            }
        }
        if ($third_arg_type && !$key_column_name_is_null) {
            $type = $have_at_least_one_res ? new T_Non_Empty_Array([$result_key_type, $result_element_type ?? Type::get_mixed()]) : new T_Array([$result_key_type, $result_element_type ?? Type::get_mixed()]);
        } else {
            $type = $have_at_least_one_res ? Type::get_non_empty_list_atomic($result_element_type ?? Type::get_mixed()) : Type::get_list_atomic($result_element_type ?? Type::get_mixed());
        }
        return new Union([$type]);
    }
    /**
     * @return TArray|TKeyedArray|TClassStringMap|null
     */
    private static function get_row_shape(?Union $row_type, Source_Analyzer $statements_source, Context $context, Code_Location $code_location): ?Atomic
    {
        if (!$row_type) {
            return null;
        }
        if (!$row_type->is_single()) {
            return null;
        }
        if ($row_type->has_array()) {
            return $row_type->get_array();
        }
        if ($row_type->has_object_type()) {
            return Get_Object_Vars_Return_Type_Provider::get_get_object_vars_return_type($row_type, $statements_source, $context, $code_location);
        }
        return null;
    }
}