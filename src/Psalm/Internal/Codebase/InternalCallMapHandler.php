<?php

declare (strict_types=1);
namespace Psalm\Internal\Codebase;

use Php_Parser;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\Project_Analyzer;
use Psalm\Internal\Provider\Node_Data_Provider;
use Psalm\Internal\Type\Comparator\Type_Comparison_Result;
use Psalm\Internal\Type\Comparator\Union_Type_Comparator;
use Psalm\Node_Type_Provider;
use Psalm\Storage\Function_Like_Parameter;
use Psalm\Type;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Callable;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Taint_Kind;
use UnexpectedValueException;
use function array_shift;
use function assert;
use function count;
use function dirname;
use function max;
use function min;
use function str_ends_with;
use function str_starts_with;
use function strpos;
use function strtolower;
use function substr;
/**
 * @internal
 *
 * Gets values from the call map array, which stores data about native functions and methods
 */
final class Internal_Call_Map_Handler
{
    private const MIN_CALLMAP_VERSION = 70;
    private const MAX_CALLMAP_VERSION = 85;
    private static ?int $loaded_php_major_version = null;
    private static ?int $loaded_php_minor_version = null;
    /**
     * @var non-empty-array<lowercase-string, array<int|string,string>>|null
     */
    private static ?array $call_map = null;
    /**
     * @var array<string, non-empty-list<TCallable>>|null
     */
    private static ?array $call_map_callables = [];
    /**
     * @var non-empty-array<string, non-empty-list<list<TaintKind::*>>>|null
     */
    private static ?array $taint_sink_map = null;
    /**
     * @param  list<PhpParser\Node\Arg>   $args
     */
    public static function get_callable_from_call_map_by_id(Codebase $codebase, string $method_id, array $args, ?Node_Data_Provider $nodes): T_Callable
    {
        $possible_callables = self::get_callables_from_call_map($method_id);
        if ($possible_callables === null) {
            throw new UnexpectedValueException('Not expecting $function_param_options to be null for ' . $method_id);
        }
        return self::get_matching_callable_from_call_map_options($codebase, $possible_callables, $args, $nodes, $method_id);
    }
    /**
     * @param  non-empty-list<TCallable>  $callables
     * @param  list<PhpParser\Node\Arg>                 $args
     */
    public static function get_matching_callable_from_call_map_options(Codebase $codebase, array $callables, array $args, ?Node_Type_Provider $nodes, string $method_id): T_Callable
    {
        if (count($callables) === 1) {
            return $callables[0];
        }
        $matching_param_count_callable = null;
        $matching_coerced_param_count_callable = null;
        foreach ($callables as $possible_callable) {
            $possible_function_params = $possible_callable->params;
            assert($possible_function_params !== null);
            $all_args_match = true;
            $type_coerced = false;
            $last_param = count($possible_function_params) ? $possible_function_params[count($possible_function_params) - 1] : null;
            $mandatory_param_count = count($possible_function_params);
            foreach ($possible_function_params as $i => $possible_function_param) {
                if ($possible_function_param->is_optional) {
                    $mandatory_param_count = $i;
                    break;
                }
            }
            if ($mandatory_param_count > count($args) && !($last_param && $last_param->is_variadic)) {
                continue;
            }
            foreach ($args as $argument_offset => $arg) {
                if ($argument_offset >= count($possible_function_params)) {
                    if (!$last_param || !$last_param->is_variadic) {
                        $all_args_match = false;
                        break;
                    }
                    $function_param = $last_param;
                } else {
                    $function_param = $possible_function_params[$argument_offset];
                }
                $param_type = $function_param->type;
                if (!$param_type) {
                    continue;
                }
                if (!$nodes) {
                    continue;
                }
                if (!$arg_type = $nodes->get_type($arg->value)) {
                    continue;
                }
                if ($arg_type->has_mixed()) {
                    continue;
                }
                if ($arg->unpack && !$function_param->is_variadic) {
                    if ($arg_type->has_array()) {
                        /**
                         * @var TArray|TKeyedArray
                         */
                        $array_atomic_type = $arg_type->get_array();
                        if ($array_atomic_type instanceof T_Keyed_Array) {
                            $arg_type = $array_atomic_type->get_generic_value_type();
                        } else {
                            $arg_type = $array_atomic_type->type_params[1];
                        }
                    }
                }
                $arg_result = new Type_Comparison_Result();
                if (Union_Type_Comparator::is_contained_by($codebase, $arg_type, $param_type, true, true, $arg_result) || $arg_result->type_coerced) {
                    if ($arg_result->type_coerced) {
                        $type_coerced = true;
                    }
                    continue;
                }
                $all_args_match = false;
                break;
            }
            if (count($args) === count($possible_function_params)) {
                $matching_param_count_callable = $possible_callable;
            }
            if ($all_args_match && (!$type_coerced || $method_id === 'max' || $method_id === 'min')) {
                return $possible_callable;
            }
            if ($all_args_match) {
                $matching_coerced_param_count_callable = $possible_callable;
            }
        }
        if ($matching_coerced_param_count_callable) {
            return $matching_coerced_param_count_callable;
        }
        if ($matching_param_count_callable) {
            return $matching_param_count_callable;
        }
        // if we don't succeed in finding a match, set to the first possible and wait for issues below
        return $callables[0];
    }
    /**
     * @return non-empty-list<TCallable>|null
     */
    public static function get_callables_from_call_map(string $function_id): ?array
    {
        $call_map_key = strtolower($function_id);
        if (isset(self::$call_map_callables[$call_map_key])) {
            return self::$call_map_callables[$call_map_key];
        }
        $call_map = self::get_call_map();
        if (!isset($call_map[$call_map_key])) {
            return null;
        }
        $call_map_functions = [];
        $call_map_functions[] = $call_map[$call_map_key];
        for ($i = 1; $i < 10; ++$i) {
            if (!isset($call_map[$call_map_key . '\'' . $i])) {
                break;
            }
            $call_map_functions[] = $call_map[$call_map_key . '\'' . $i];
        }
        $possible_callables = [];
        foreach ($call_map_functions as $call_map_function_args) {
            $return_type = Type::parse_string(array_shift($call_map_function_args));
            $function_params = [];
            $arg_offset = 0;
            /** @var string $arg_name - key type changed with above array_shift */
            foreach ($call_map_function_args as $arg_name => $arg_type) {
                $by_reference = false;
                $optional = false;
                $variadic = false;
                if ($arg_name[0] === '&') {
                    $arg_name = substr($arg_name, 1);
                    $by_reference = true;
                }
                if (str_ends_with($arg_name, '=')) {
                    $arg_name = substr($arg_name, 0, -1);
                    $optional = true;
                }
                if (str_starts_with($arg_name, '...')) {
                    $arg_name = substr($arg_name, 3);
                    $variadic = true;
                }
                $param_type = Type::parse_string($arg_type);
                $out_type = null;
                if ($by_reference && false !== $p = strpos($arg_name, ' ')) {
                    $ref_type = substr($arg_name, 0, $p);
                    $arg_name = substr($arg_name, $p + 1);
                    if ($ref_type === 'w') {
                        $out_type = $param_type;
                        $param_type = Type::get_mixed();
                    }
                }
                $function_param = new Function_Like_Parameter($arg_name, $by_reference, $param_type, $param_type, null, null, $optional, false, $variadic);
                if ($out_type) {
                    $function_param->out_type = $out_type;
                }
                if ($arg_name === 'haystack') {
                    $function_param->expect_variable = true;
                }
                if (isset(self::$taint_sink_map[$call_map_key][$arg_offset])) {
                    $function_param->sinks = self::$taint_sink_map[$call_map_key][$arg_offset];
                }
                $function_param->signature_type = null;
                $function_params[] = $function_param;
                $arg_offset++;
            }
            $possible_callables[] = new T_Callable('callable', $function_params, $return_type);
        }
        self::$call_map_callables[$call_map_key] = $possible_callables;
        return $possible_callables;
    }
    /**
     * Gets the method/function call map
     *
     * @return non-empty-array<string, array<int|string, string>>
     * @psalm-assert !null self::$taint_sink_map
     * @psalm-assert !null self::$call_map
     * @psalm-suppress UnresolvableInclude
     */
    public static function get_call_map(): array
    {
        $codebase = Project_Analyzer::get_instance()->get_codebase();
        $analyzer_major_version = $codebase->get_major_analysis_php_version();
        $analyzer_minor_version = $codebase->get_minor_analysis_php_version();
        if (self::$call_map !== null && $analyzer_major_version === self::$loaded_php_major_version && $analyzer_minor_version === self::$loaded_php_minor_version) {
            return self::$call_map;
        }
        $analyzer_version_int = min(self::MAX_CALLMAP_VERSION, max(self::MIN_CALLMAP_VERSION, (int) ($analyzer_major_version . $analyzer_minor_version)));
        /** @var non-empty-array<lowercase-string, array<int|string, string>> */
        $call_map = require dirname(__DIR__, 4) . "/dictionaries/CallMap_{$analyzer_version_int}.php";
        self::$call_map = $call_map;
        assert(!empty(self::$call_map));
        self::$loaded_php_major_version = $analyzer_major_version;
        self::$loaded_php_minor_version = $analyzer_minor_version;
        /**
         * @var non-empty-array<string, non-empty-list<list<TaintKind::*>>>
         */
        $taint_map_data = require dirname(__DIR__, 4) . '/dictionaries/InternalTaintSinkMap.php';
        $taint_map = [];
        foreach ($taint_map_data as $key => $value) {
            $cased_key = strtolower($key);
            $taint_map[$cased_key] = $value;
        }
        self::$taint_sink_map = $taint_map;
        return self::$call_map;
    }
    public static function in_call_map(string $key): bool
    {
        return isset(self::get_call_map()[strtolower($key)]);
    }
    public static function clear_cache(): void
    {
        self::$call_map_callables = [];
    }
}