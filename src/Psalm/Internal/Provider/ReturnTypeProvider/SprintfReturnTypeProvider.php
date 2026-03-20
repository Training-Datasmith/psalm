<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider\Return_Type_Provider;

use Argument_Count_Error;
use Override;
use Psalm\Issue\Invalid_Argument;
use Psalm\Issue\Redundant_Function_Call;
use Psalm\Issue\Too_Few_Arguments;
use Psalm\Issue\Too_Many_Arguments;
use Psalm\Issue_Buffer;
use Psalm\Plugin\Event_Handler\Event\Function_Return_Type_Provider_Event;
use Psalm\Plugin\Event_Handler\Function_Return_Type_Provider_Interface;
use Psalm\Type;
use Psalm\Type\Atomic\T_Class_String;
use Psalm\Type\Atomic\T_Float;
use Psalm\Type\Atomic\T_Int;
use Psalm\Type\Atomic\T_Literal_String;
use Psalm\Type\Atomic\T_Non_Empty_Nonspecific_Literal_String;
use Psalm\Type\Atomic\T_Non_Empty_String;
use Psalm\Type\Atomic\T_Numeric;
use Psalm\Type\Union;
use Value_Error;
use function array_fill;
use function array_pop;
use function count;
use function is_string;
use function preg_match;
use function sprintf;
use function strlen;
/**
 * @internal
 */
final class Sprintf_Return_Type_Provider implements Function_Return_Type_Provider_Interface
{
    /**
     * @return array<lowercase-string>
     */
    #[Override]
    public static function get_function_ids(): array
    {
        return ['printf', 'sprintf'];
    }
    #[Override]
    public static function get_function_return_type(Function_Return_Type_Provider_Event $event): ?Union
    {
        $statements_source = $event->get_statements_source();
        $call_args = $event->get_call_args();
        // invalid - will already report an error for the params anyway
        if (count($call_args) < 1) {
            return null;
        }
        $has_splat_args = false;
        $node_type_provider = $statements_source->get_node_type_provider();
        foreach ($call_args as $call_arg) {
            $type = $node_type_provider->get_type($call_arg->value);
            if ($type === null) {
                continue;
            }
            // if it's an array, used with splat operator
            // we cannot validate it reliably below and report false positive errors
            if ($type->is_array()) {
                $has_splat_args = true;
                break;
            }
        }
        // there is only 1 array argument, fall back to the default handling
        // eventually this could be refined
        // to check if it's an array with literal string as first element for further checking
        if (count($call_args) === 1 && $has_splat_args === true) {
            Issue_Buffer::maybe_add(new Redundant_Function_Call('Using the splat operator is redundant, as v' . $event->get_function_id() . ' without splat operator can be used instead of ' . $event->get_function_id(), $event->get_code_location()), $statements_source->get_suppressed_issues());
            return null;
        }
        // it makes no sense to use sprintf when there is only 1 arg (the format)
        // as it wouldn't have any placeholders
        // if it's a literal string, we can check it further though!
        $first_arg_type = $node_type_provider->get_type($call_args[0]->value);
        if (count($call_args) === 1 && ($first_arg_type === null || !$first_arg_type->is_single_string_literal())) {
            Issue_Buffer::maybe_add(new Redundant_Function_Call('Using ' . $event->get_function_id() . ' with a single argument is redundant, since there are no placeholder params to be substituted', $event->get_code_location()), $statements_source->get_suppressed_issues());
            return null;
        }
        // PHP 7 handling for formats that do not contain anything but placeholders
        $is_falsable = true;
        foreach ($call_args as $index => $call_arg) {
            $type = $node_type_provider->get_type($call_arg->value);
            if ($type === null && $index === 0 && $event->get_function_id() === 'printf') {
                // printf only has the format validated above
                // don't change the return type
                break;
            }
            if ($type === null) {
                continue;
            }
            if ($index === 0 && $type->is_single_string_literal()) {
                if ($type->get_single_string_literal()->value === '') {
                    Issue_Buffer::maybe_add(new Redundant_Function_Call('Calling ' . $event->get_function_id() . ' with an empty first argument does nothing', $event->get_code_location()), $statements_source->get_suppressed_issues());
                    if ($event->get_function_id() === 'printf') {
                        return Type::get_int(false, 0);
                    }
                    return Type::get_string('');
                }
                // there are probably additional formats that return an empty string, this is just a starting point
                if (preg_match('/^%(?:\d+\$)?[-+]?0(?:\.0)?s$/', $type->get_single_string_literal()->value) === 1) {
                    Issue_Buffer::maybe_add(new Invalid_Argument('The pattern of argument 1 of ' . $event->get_function_id() . ' will always return an empty string', $event->get_code_location(), $event->get_function_id()), $statements_source->get_suppressed_issues());
                    if ($event->get_function_id() === 'printf') {
                        return Type::get_int(false, 0);
                    }
                    return Type::get_string('');
                }
                // these placeholders still fall back to a generic return type,
                // but we validate their format and argument count first
                $has_complex_placeholder = preg_match('/%(?:\d+\$)?[-+]?(?:' . '(?:\d+|\*(?:\d+\$)?)(?:\.(?:\d+|\*(?:\d+\$)?))?' . '|\.\*(?:\d+\$)?' . ')[bcdouxXeEfFgGhHs]/', $type->get_single_string_literal()->value) === 1;
                // assume a random, high number for tests
                $provided_placeholders_count = $has_splat_args === true ? 100 : count($call_args) - 1;
                // Use integer dummies for * width/precision so PHP validates arity without raising a false ValueError.
                $dummy = array_fill(0, $provided_placeholders_count, $has_complex_placeholder ? 0 : '');
                // check if we have enough/too many arguments and a valid format
                $initial_result = null;
                while (count($dummy) > -1) {
                    $result = null;
                    try {
                        // before PHP 8, an uncatchable Warning is thrown if too few arguments are passed
                        // which is ignored and handled below instead
                        $result = @sprintf($type->get_single_string_literal()->value, ...$dummy);
                        if ($initial_result === null) {
                            $initial_result = $result;
                            if ($result === $type->get_single_string_literal()->value) {
                                if (count($call_args) > 1) {
                                    // we need to report this here too, since we return early without further validation
                                    // otherwise people who have suspended RedundantFunctionCall errors
                                    // will not get an error for this
                                    Issue_Buffer::maybe_add(new Too_Many_Arguments('Too many arguments for the number of placeholders in ' . $event->get_function_id(), $event->get_code_location(), $event->get_function_id()), $statements_source->get_suppressed_issues());
                                }
                                // the same error as above, but we have validated the pattern now
                                if (count($call_args) === 1) {
                                    Issue_Buffer::maybe_add(new Redundant_Function_Call('Using ' . $event->get_function_id() . ' with a single argument is redundant,' . ' since there are no placeholder params to be substituted', $event->get_code_location()), $statements_source->get_suppressed_issues());
                                } else {
                                    Issue_Buffer::maybe_add(new Redundant_Function_Call('Argument 1 of ' . $event->get_function_id() . ' does not contain any placeholders', $event->get_code_location()), $statements_source->get_suppressed_issues());
                                }
                                if ($event->get_function_id() === 'printf') {
                                    return Type::get_int(false, strlen($type->get_single_string_literal()->value));
                                }
                                return $type;
                            }
                        }
                    } catch (Value_Error $value_error) {
                        // PHP 8
                        // the format is invalid
                        Issue_Buffer::maybe_add(new Invalid_Argument('Argument 1 of ' . $event->get_function_id() . ' is invalid - ' . $value_error->get_message(), $event->get_code_location(), $event->get_function_id()), $statements_source->get_suppressed_issues());
                        break 2;
                    } catch (Argument_Count_Error) {
                        // PHP 8
                        if (count($dummy) === $provided_placeholders_count) {
                            Issue_Buffer::maybe_add(new Too_Few_Arguments('Too few arguments for ' . $event->get_function_id(), $event->get_code_location(), $event->get_function_id()), $statements_source->get_suppressed_issues());
                            break 2;
                        }
                    }
                    // we can only validate the format and arg 1 when using splat
                    if ($has_splat_args === true) {
                        break;
                    }
                    if (is_string($result) && count($dummy) + 1 <= $provided_placeholders_count) {
                        Issue_Buffer::maybe_add(new Too_Many_Arguments('Too many arguments for the number of placeholders in ' . $event->get_function_id(), $event->get_code_location(), $event->get_function_id()), $statements_source->get_suppressed_issues());
                        break;
                    }
                    if (!is_string($result)) {
                        break;
                    }
                    // abort if it's empty, since we checked everything
                    if (array_pop($dummy) === null) {
                        break;
                    }
                }
                if ($has_complex_placeholder) {
                    return null;
                }
                if ($event->get_function_id() === 'printf') {
                    // printf only has the format validated above
                    // don't change the return type
                    return null;
                }
                if ($initial_result !== null && $initial_result !== '') {
                    return Type::get_non_empty_string();
                }
                // if we didn't have any valid result
                // the pattern is invalid or not yet supported by the return type provider
                if ($initial_result === null) {
                    return null;
                }
                // the initial result is an empty string
                // which means the format is valid and it depends on the args, whether it is non-empty-string or not
                $is_falsable = false;
            }
            if ($index === 0 && $event->get_function_id() === 'printf') {
                // printf only has the format validated above
                // don't change the return type
                break;
            }
            if ($index === 0) {
                continue;
            }
            // if the function has more arguments than the pattern has placeholders, this could be a false positive
            // if the param is not used in the pattern
            if ($type->is_non_empty_string() || $type->is_int() || $type->is_float()) {
                return Type::get_non_empty_string();
            }
            // check for unions of either
            $atomic_types = $type->get_atomic_types();
            if ($atomic_types === []) {
                continue;
            }
            foreach ($atomic_types as $atomic_type) {
                if ($atomic_type instanceof T_Non_Empty_String) {
                    // valid non-empty types, potentially there are more though
                    continue;
                }
                if ($atomic_type instanceof T_Non_Empty_Nonspecific_Literal_String) {
                    // valid non-empty types, potentially there are more though
                    continue;
                }
                if ($atomic_type instanceof T_Class_String) {
                    // valid non-empty types, potentially there are more though
                    continue;
                }
                if ($atomic_type instanceof T_Literal_String && $atomic_type->value !== '') {
                    // valid non-empty types, potentially there are more though
                    continue;
                }
                if ($atomic_type instanceof T_Int) {
                    // valid non-empty types, potentially there are more though
                    continue;
                }
                if ($atomic_type instanceof T_Float) {
                    // valid non-empty types, potentially there are more though
                    continue;
                }
                if ($atomic_type instanceof T_Numeric) {
                    // valid non-empty types, potentially there are more though
                    continue;
                }
                // empty or generic string
                // or other unhandled type
                continue 2;
            }
            return Type::get_non_empty_string();
        }
        if ($is_falsable === false) {
            return Type::get_string();
        }
        return null;
    }
}