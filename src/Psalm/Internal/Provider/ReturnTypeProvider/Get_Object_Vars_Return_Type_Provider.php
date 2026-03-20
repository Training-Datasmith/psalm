<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider\Return_Type_Provider;

use Override;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Internal\Analyzer\Class_Analyzer;
use Psalm\Internal\Analyzer\Source_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Fetch\Atomic_Property_Fetch_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Plugin\Event_Handler\Event\Function_Return_Type_Provider_Event;
use Psalm\Plugin\Event_Handler\Function_Return_Type_Provider_Interface;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Generic_Object;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Object_With_Properties;
use Psalm\Type\Union;
use Unit_Enum;
use stdClass;
use function reset;
use function strtolower;
/**
 * @internal
 */
final class Get_Object_Vars_Return_Type_Provider implements Function_Return_Type_Provider_Interface
{
    #[Override]
    public static function get_function_ids(): array
    {
        return ['get_object_vars'];
    }
    private static ?T_Array $fallback = null;
    /**
     * @return TArray|TKeyedArray
     */
    public static function get_get_object_vars_return_type(Union $first_arg_type, Source_Analyzer $statements_source, Context $context, Code_Location $location): Atomic
    {
        self::$fallback ??= new T_Array([Type::get_string(), Type::get_mixed()]);
        if ($first_arg_type->is_single()) {
            $atomics = $first_arg_type->get_atomic_types();
            $object_type = reset($atomics);
            if ($object_type instanceof Atomic\T_Enum_Case) {
                $properties = ['name' => new Union([Type::get_atomic_string_from_literal($object_type->case_name)])];
                $codebase = $statements_source->get_codebase();
                $enum_classlike_storage = $codebase->classlike_storage_provider->get($object_type->value);
                if ($enum_classlike_storage->enum_type === null) {
                    return new T_Keyed_Array($properties);
                }
                $enum_case_storage = $enum_classlike_storage->enum_cases[$object_type->case_name];
                $case_value = $enum_case_storage->get_value($statements_source->get_codebase()->classlikes);
                if ($case_value !== null) {
                    $properties['value'] = new Union([$case_value]);
                }
                return new T_Keyed_Array($properties);
            }
            if ($object_type instanceof T_Object_With_Properties) {
                if ([] === $object_type->properties) {
                    return self::$fallback;
                }
                return new T_Keyed_Array($object_type->properties);
            }
            if ($object_type instanceof T_Named_Object) {
                if (strtolower($object_type->value) === strtolower(stdClass::class)) {
                    return self::$fallback;
                }
                $codebase = $statements_source->get_codebase();
                $class_storage = $codebase->classlikes->get_storage_for($object_type->value);
                if (null === $class_storage) {
                    return self::$fallback;
                }
                if ([] === $class_storage->appearing_property_ids) {
                    if ($class_storage->final) {
                        return Type::get_empty_array_atomic();
                    }
                    return self::$fallback;
                }
                $properties = [];
                foreach ($class_storage->appearing_property_ids as $name => $property_id) {
                    if (Class_Analyzer::check_property_visibility($property_id, $context, $statements_source, $location, $statements_source->get_suppressed_issues(), false) === true) {
                        $property_type = $codebase->properties->get_property_type($property_id, false, $statements_source, $context);
                        if (!$property_type) {
                            continue;
                        }
                        $property_type = $object_type instanceof T_Generic_Object ? Atomic_Property_Fetch_Analyzer::localize_property_type($codebase, $property_type, $object_type, $class_storage, $class_storage) : $property_type;
                        $properties[$name] = $property_type;
                    }
                }
                if ([] === $properties) {
                    if ($class_storage->final) {
                        return Type::get_empty_array_atomic();
                    }
                    return self::$fallback;
                }
                return new T_Keyed_Array($properties, null, $class_storage->final || $class_storage->name === Unit_Enum::class || $codebase->interface_extends($class_storage->name, Unit_Enum::class) ? null : [Type::get_string(), Type::get_mixed()]);
            }
        }
        return self::$fallback;
    }
    #[Override]
    public static function get_function_return_type(Function_Return_Type_Provider_Event $event): ?Union
    {
        $statements_source = $event->get_statements_source();
        $call_args = $event->get_call_args();
        if (!$statements_source instanceof Statements_Analyzer || !$call_args) {
            return Type::get_mixed();
        }
        if (($first_arg_type = $statements_source->node_data->get_type($call_args[0]->value)) && $first_arg_type->is_object_type()) {
            return new Union([self::get_get_object_vars_return_type($first_arg_type, $statements_source, $event->get_context(), $event->get_code_location())]);
        }
        return null;
    }
}