<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider\Return_Type_Provider;

use Override;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Type\Comparator\Union_Type_Comparator;
use Psalm\Plugin\Event_Handler\Event\Function_Return_Type_Provider_Event;
use Psalm\Plugin\Event_Handler\Function_Return_Type_Provider_Interface;
use Psalm\Type;
use Psalm\Type\Atomic\T_False;
use Psalm\Type\Atomic\T_Int;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Atomic\T_Literal_Int;
use Psalm\Type\Atomic\T_Null;
use Psalm\Type\Atomic\T_String;
use Psalm\Type\Union;
use function array_fill_keys;
use const PHP_URL_FRAGMENT;
use const PHP_URL_HOST;
use const PHP_URL_PASS;
use const PHP_URL_PATH;
use const PHP_URL_PORT;
use const PHP_URL_QUERY;
use const PHP_URL_SCHEME;
use const PHP_URL_USER;
/**
 * @internal
 */
final class Parse_Url_Return_Type_Provider implements Function_Return_Type_Provider_Interface
{
    /**
     * @return array<lowercase-string>
     */
    #[Override]
    public static function get_function_ids(): array
    {
        return ['parse_url'];
    }
    private static ?Union $acceptable_int_component_type = null;
    private static ?Union $acceptable_string_component_type = null;
    private static ?Union $nullable_falsable_int = null;
    private static ?Union $nullable_falsable_string = null;
    private static ?Union $nullable_string_or_int = null;
    private static ?Union $return_type = null;
    #[Override]
    public static function get_function_return_type(Function_Return_Type_Provider_Event $event): Union
    {
        $statements_source = $event->get_statements_source();
        $call_args = $event->get_call_args();
        if (!$statements_source instanceof Statements_Analyzer) {
            return Type::get_mixed();
        }
        if (isset($call_args[1])) {
            $is_default_component = false;
            if ($component_type = $statements_source->node_data->get_type($call_args[1]->value)) {
                if (!$component_type->has_mixed()) {
                    $codebase = $statements_source->get_codebase();
                    self::$acceptable_string_component_type ??= new Union([new T_Literal_Int(PHP_URL_SCHEME), new T_Literal_Int(PHP_URL_USER), new T_Literal_Int(PHP_URL_PASS), new T_Literal_Int(PHP_URL_HOST), new T_Literal_Int(PHP_URL_PATH), new T_Literal_Int(PHP_URL_QUERY), new T_Literal_Int(PHP_URL_FRAGMENT)]);
                    self::$acceptable_int_component_type ??= new Union([new T_Literal_Int(PHP_URL_PORT)]);
                    if (Union_Type_Comparator::is_contained_by($codebase, $component_type, self::$acceptable_string_component_type)) {
                        self::$nullable_falsable_string ??= new Union([new T_String(), new T_False(), new T_Null()], ['ignore_nullable_issues' => $statements_source->get_codebase()->config->ignore_internal_nullable_issues, 'ignore_falsable_issues' => $statements_source->get_codebase()->config->ignore_internal_falsable_issues]);
                        return self::$nullable_falsable_string;
                    }
                    if (Union_Type_Comparator::is_contained_by($codebase, $component_type, self::$acceptable_int_component_type)) {
                        self::$nullable_falsable_int ??= new Union([new T_Int(), new T_False(), new T_Null()], ['ignore_nullable_issues' => $statements_source->get_codebase()->config->ignore_internal_nullable_issues, 'ignore_falsable_issues' => $statements_source->get_codebase()->config->ignore_internal_falsable_issues]);
                        return self::$nullable_falsable_int;
                    }
                    if ($component_type->is_single_int_literal()) {
                        $component_type_type = $component_type->get_single_int_literal();
                        $is_default_component = $component_type_type->value <= -1;
                    }
                }
            }
            if (!$is_default_component) {
                self::$nullable_string_or_int ??= new Union([new T_String(), new T_Int(), new T_Null()], ['ignore_nullable_issues' => $statements_source->get_codebase()->config->ignore_internal_nullable_issues]);
                return self::$nullable_string_or_int;
            }
        }
        if (!self::$return_type) {
            $component_types = array_fill_keys(['scheme', 'user', 'pass', 'host', 'path', 'query', 'fragment'], new Union([new T_String()], ['possibly_undefined' => true]));
            $component_types['port'] = new Union([new T_Int()], ['possibly_undefined' => true]);
            self::$return_type = new Union([new T_Keyed_Array($component_types), new T_False()], ['ignore_falsable_issues' => $statements_source->get_codebase()->config->ignore_internal_falsable_issues]);
        }
        return self::$return_type;
    }
}