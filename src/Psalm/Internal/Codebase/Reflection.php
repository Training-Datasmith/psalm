<?php

declare (strict_types=1);
namespace Psalm\Internal\Codebase;

use Exception;
use Lib_Xml_Error;
use LogicException;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\Class_Like_Analyzer;
use Psalm\Internal\Method_Identifier;
use Psalm\Internal\Provider\Class_Like_Storage_Provider;
use Psalm\Storage\Class_Constant_Storage;
use Psalm\Storage\Function_Like_Parameter;
use Psalm\Storage\Function_Storage;
use Psalm\Storage\Method_Storage;
use Psalm\Storage\Property_Storage;
use Psalm\Type;
use Psalm\Type\Union;
use ReflectionClass;
use Reflection_Exception;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Reflection_Type;
use ReflectionUnionType;
use UnexpectedValueException;
use function array_map;
use function array_merge;
use function implode;
use function strtolower;
use const PHP_VERSION_ID;
/**
 * @internal
 *
 * Handles information gleaned from class and function reflection
 */
final class Reflection
{
    /**
     * @var array<string, FunctionStorage>
     */
    private static array $builtin_functions = [];
    public function __construct(private readonly Class_Like_Storage_Provider $storage_provider, private readonly Codebase $codebase)
    {
        self::$builtin_functions = [];
    }
    public function register_class(ReflectionClass $reflected_class): void
    {
        $class_name = $reflected_class->name;
        if ($class_name === Lib_Xml_Error::class) {
            $class_name = 'libXMLError';
        }
        $class_name_lower = strtolower($class_name);
        try {
            $this->storage_provider->get($class_name_lower);
            return;
        } catch (Exception) {
            // this is fine
        }
        $reflected_parent_class = $reflected_class->get_parent_class();
        $storage = $this->storage_provider->create($class_name);
        $storage->abstract = $reflected_class->is_abstract();
        $storage->is_interface = $reflected_class->is_interface();
        $storage->potential_declaring_method_ids['__construct'][$class_name_lower . '::__construct'] = true;
        if ($reflected_parent_class) {
            $parent_class_name = $reflected_parent_class->get_name();
            $this->register_class($reflected_parent_class);
            $parent_class_name_lc = strtolower($parent_class_name);
            $parent_storage = $this->storage_provider->get($parent_class_name_lc);
            $this->register_inherited_methods($class_name_lower, $parent_class_name_lc);
            $this->register_inherited_properties($class_name_lower, $parent_class_name_lc);
            $storage->class_implements = $parent_storage->class_implements;
            $storage->constants = $parent_storage->constants;
            $storage->parent_classes = array_merge([$parent_class_name_lc => $parent_class_name], $parent_storage->parent_classes);
            $storage->used_traits = $parent_storage->used_traits;
        }
        $class_properties = $reflected_class->get_properties();
        $public_mapped_properties = Property_Map::in_property_map($class_name) ? Property_Map::get_property_map()[strtolower($class_name)] : [];
        foreach ($class_properties as $class_property) {
            $property_name = $class_property->get_name();
            $storage->properties[$property_name] = new Property_Storage();
            $storage->properties[$property_name]->type = Type::get_mixed();
            if ($class_property->is_static()) {
                $storage->properties[$property_name]->is_static = true;
            }
            if ($class_property->is_public()) {
                $storage->properties[$property_name]->visibility = Class_Like_Analyzer::VISIBILITY_PUBLIC;
            } elseif ($class_property->is_protected()) {
                $storage->properties[$property_name]->visibility = Class_Like_Analyzer::VISIBILITY_PROTECTED;
            } elseif ($class_property->is_private()) {
                $storage->properties[$property_name]->visibility = Class_Like_Analyzer::VISIBILITY_PRIVATE;
            }
            $property_id = $class_property->class . '::$' . $property_name;
            $storage->declaring_property_ids[$property_name] = $class_property->class;
            $storage->appearing_property_ids[$property_name] = $property_id;
            if (!$class_property->is_private()) {
                $storage->inheritable_property_ids[$property_name] = $property_id;
            }
        }
        // have to do this separately as there can be new properties here
        foreach ($public_mapped_properties as $property_name => $type_string) {
            $property_id = $class_name . '::$' . $property_name;
            if (!isset($storage->properties[$property_name])) {
                $storage->properties[$property_name] = new Property_Storage();
                $storage->properties[$property_name]->visibility = Class_Like_Analyzer::VISIBILITY_PUBLIC;
                $storage->declaring_property_ids[$property_name] = $class_name;
                $storage->appearing_property_ids[$property_name] = $property_id;
                $storage->inheritable_property_ids[$property_name] = $property_id;
            }
            $type = Type::parse_string($type_string);
            if ($property_id === 'DateInterval::$days') {
                /** @psalm-suppress InaccessibleProperty We just parsed this type */
                $type->ignore_falsable_issues = true;
            }
            $storage->properties[$property_name]->type = $type;
        }
        /** @var array<string, int|string|float|null|array> */
        $class_constants = $reflected_class->get_constants();
        foreach ($class_constants as $name => $value) {
            $storage->constants[$name] = new Class_Constant_Storage(Class_Like_Analyzer::get_type_from_value($value), new Union([Constant_Type_Resolver::get_literal_type_from_scalar_value($value)]), Class_Like_Analyzer::VISIBILITY_PUBLIC, null);
        }
        if ($reflected_class->is_interface()) {
            $this->codebase->classlikes->add_fully_qualified_interface_name($class_name);
        } elseif ($reflected_class->is_trait()) {
            $this->codebase->classlikes->add_fully_qualified_trait_name($class_name);
        } else {
            $this->codebase->classlikes->add_fully_qualified_class_name($class_name);
        }
        $reflection_methods = $reflected_class->get_methods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED);
        if ($class_name_lower === 'generator') {
            $storage->template_types = ['TKey' => ['Generator' => Type::get_mixed()], 'TValue' => ['Generator' => Type::get_mixed()]];
        }
        $interfaces = $reflected_class->get_interfaces();
        foreach ($interfaces as $interface) {
            $interface_name = $interface->get_name();
            $this->register_class($interface);
            if ($reflected_class->is_interface()) {
                $storage->parent_interfaces[strtolower($interface_name)] = $interface_name;
            } else {
                $storage->class_implements[strtolower($interface_name)] = $interface_name;
            }
        }
        foreach ($reflection_methods as $reflection_method) {
            $method_reflection_class = $reflection_method->get_declaring_class();
            $this->register_class($method_reflection_class);
            $this->extract_reflection_method_info($reflection_method);
            if ($reflection_method->class !== $class_name && ($class_name !== 'SoapFault' || $reflection_method->name !== '__construct')) {
                $reflection_method_name = strtolower($reflection_method->name);
                $reflection_method_class = $reflection_method->class;
                $this->codebase->methods->set_declaring_method_id($class_name, $reflection_method_name, $reflection_method_class, $reflection_method_name);
                $this->codebase->methods->set_appearing_method_id($class_name, $reflection_method_name, $reflection_method_class, $reflection_method_name);
            }
        }
    }
    public function extract_reflection_method_info(ReflectionMethod $method): void
    {
        $method_name_lc = strtolower($method->get_name());
        $fq_class_name = $method->class;
        $fq_class_name_lc = strtolower($fq_class_name);
        $class_storage = $this->storage_provider->get($fq_class_name_lc);
        if (isset($class_storage->methods[$method_name_lc])) {
            return;
        }
        $method_id = $method->class . '::' . $method_name_lc;
        $storage = $class_storage->methods[$method_name_lc] = new Method_Storage();
        $storage->cased_name = $method->name;
        $storage->defining_fqcln = $method->class;
        if ($method_name_lc === $fq_class_name_lc) {
            $this->codebase->methods->set_declaring_method_id($fq_class_name, '__construct', $fq_class_name, $method_name_lc);
            $this->codebase->methods->set_appearing_method_id($fq_class_name, '__construct', $fq_class_name, $method_name_lc);
        }
        $declaring_class = $method->get_declaring_class();
        $storage->is_static = $method->is_static();
        $storage->abstract = $method->is_abstract();
        $storage->mutation_free = $storage->external_mutation_free = $method_name_lc === '__construct' && $fq_class_name_lc === 'datetimezone';
        $class_storage->declaring_method_ids[$method_name_lc] = new Method_Identifier($declaring_class->name, $method_name_lc);
        $class_storage->inheritable_method_ids[$method_name_lc] = $class_storage->declaring_method_ids[$method_name_lc];
        $class_storage->appearing_method_ids[$method_name_lc] = $class_storage->declaring_method_ids[$method_name_lc];
        $class_storage->overridden_method_ids[$method_name_lc] = [];
        $storage->visibility = $method->is_private() ? Class_Like_Analyzer::VISIBILITY_PRIVATE : ($method->is_protected() ? Class_Like_Analyzer::VISIBILITY_PROTECTED : Class_Like_Analyzer::VISIBILITY_PUBLIC);
        $callables = Internal_Call_Map_Handler::get_callables_from_call_map($method_id);
        if ($callables && $callables[0]->params !== null && $callables[0]->return_type !== null) {
            $storage->set_params([]);
            foreach ($callables[0]->params as $param) {
                if ($param->type) {
                    /** @psalm-suppress UnusedMethodCall */
                    $param->type->queue_class_likes_for_scanning($this->codebase);
                }
            }
            $storage->set_params($callables[0]->params);
            $storage->return_type = $callables[0]->return_type;
            /** @psalm-suppress UnusedMethodCall */
            $storage->return_type->queue_class_likes_for_scanning($this->codebase);
        } else {
            $params = $method->get_parameters();
            $storage->set_params([]);
            foreach ($params as $param) {
                $param_array = $this->get_reflection_param_data($param);
                $storage->add_param($param_array);
            }
        }
        $storage->required_param_count = 0;
        foreach ($storage->params as $i => $param) {
            if (!$param->is_optional && !$param->is_variadic) {
                $storage->required_param_count = $i + 1;
            }
        }
    }
    private function get_reflection_param_data(ReflectionParameter $param): Function_Like_Parameter
    {
        $param_type = self::get_psalm_type_from_reflection_type($param->get_type());
        $param_name = $param->get_name();
        $is_optional = $param->is_optional();
        $parameter = new Function_Like_Parameter($param_name, $param->is_passed_by_reference(), $param_type, $param_type, null, null, $is_optional, $param_type->is_nullable(), $param->is_variadic());
        $parameter->signature_type = Type::get_mixed();
        return $parameter;
    }
    /**
     * @param  callable-string $function_id
     * @return false|null
     */
    public function register_function(string $function_id): ?bool
    {
        try {
            $reflection_function = new ReflectionFunction($function_id);
            $callmap_callable = null;
            if (isset(self::$builtin_functions[$function_id])) {
                return null;
            }
            $storage = self::$builtin_functions[$function_id] = new Function_Storage();
            if (Internal_Call_Map_Handler::in_call_map($function_id)) {
                $callmap_callable = Internal_Call_Map_Handler::get_callable_from_call_map_by_id($this->codebase, $function_id, [], null);
            }
            if ($callmap_callable !== null && $callmap_callable->params !== null && $callmap_callable->return_type !== null) {
                $storage->set_params($callmap_callable->params);
                $storage->return_type = $callmap_callable->return_type;
            } else {
                $reflection_params = $reflection_function->get_parameters();
                foreach ($reflection_params as $param) {
                    $param_obj = $this->get_reflection_param_data($param);
                    $storage->add_param($param_obj);
                }
                if ($reflection_return_type = PHP_VERSION_ID >= 80100 ? $reflection_function->get_tentative_return_type() ?? $reflection_function->get_return_type() : $reflection_function->get_return_type()) {
                    $storage->return_type = self::get_psalm_type_from_reflection_type($reflection_return_type);
                }
            }
            $storage->pure = true;
            $storage->required_param_count = 0;
            foreach ($storage->params as $i => $param) {
                if (!$param->is_optional && !$param->is_variadic) {
                    $storage->required_param_count = $i + 1;
                }
            }
            $storage->cased_name = $reflection_function->get_name();
        } catch (Reflection_Exception) {
            return false;
        }
        return null;
    }
    /** @psalm-suppress UnusedPsalmSuppress,UndefinedClass,TypeDoesNotContainType 7.4 has no ReflectionUnionType */
    public static function get_psalm_type_from_reflection_type(?Reflection_Type $reflection_type = null): Union
    {
        if (!$reflection_type) {
            return Type::get_mixed();
        }
        if ($reflection_type instanceof ReflectionNamedType) {
            $type = $reflection_type->get_name();
        } elseif ($reflection_type instanceof ReflectionUnionType) {
            $type = implode('|', array_map(static fn(ReflectionNamedType $reflection): string => $reflection->get_name(), $reflection_type->get_types()));
        } else {
            throw new LogicException('Unexpected reflection class ' . $reflection_type::class . ' found.');
        }
        if ($reflection_type->allows_null()) {
            $type .= '|null';
        }
        return Type::parse_string($type);
    }
    private function register_inherited_methods(string $fq_class_name, string $parent_class): void
    {
        $parent_storage = $this->storage_provider->get($parent_class);
        $storage = $this->storage_provider->get($fq_class_name);
        // register where they appear (can never be in a trait)
        foreach ($parent_storage->appearing_method_ids as $method_name => $appearing_method_id) {
            $storage->appearing_method_ids[$method_name] = $appearing_method_id;
        }
        // register where they're declared
        foreach ($parent_storage->inheritable_method_ids as $method_name => $declaring_method_id) {
            $storage->declaring_method_ids[$method_name] = $declaring_method_id;
            $storage->inheritable_method_ids[$method_name] = $declaring_method_id;
            $storage->overridden_method_ids[$method_name][$declaring_method_id->fq_class_name] = $declaring_method_id;
        }
    }
    /**
     * @param lowercase-string $fq_class_name
     * @param lowercase-string $parent_class
     */
    private function register_inherited_properties(string $fq_class_name, string $parent_class): void
    {
        $parent_storage = $this->storage_provider->get($parent_class);
        $storage = $this->storage_provider->get($fq_class_name);
        // register where they appear (can never be in a trait)
        foreach ($parent_storage->appearing_property_ids as $property_name => $appearing_property_id) {
            if (!$parent_storage->is_trait && isset($parent_storage->properties[$property_name]) && $parent_storage->properties[$property_name]->visibility === Class_Like_Analyzer::VISIBILITY_PRIVATE) {
                continue;
            }
            $storage->appearing_property_ids[$property_name] = $appearing_property_id;
        }
        // register where they're declared
        foreach ($parent_storage->declaring_property_ids as $property_name => $declaring_property_class) {
            if (!$parent_storage->is_trait && isset($parent_storage->properties[$property_name]) && $parent_storage->properties[$property_name]->visibility === Class_Like_Analyzer::VISIBILITY_PRIVATE) {
                continue;
            }
            $storage->declaring_property_ids[$property_name] = strtolower($declaring_property_class);
        }
        // register where they're declared
        foreach ($parent_storage->inheritable_property_ids as $property_name => $inheritable_property_id) {
            if (!$parent_storage->is_trait && isset($parent_storage->properties[$property_name]) && $parent_storage->properties[$property_name]->visibility === Class_Like_Analyzer::VISIBILITY_PRIVATE) {
                continue;
            }
            $storage->inheritable_property_ids[$property_name] = $inheritable_property_id;
        }
    }
    public function has_function(string $function_id): bool
    {
        return isset(self::$builtin_functions[$function_id]);
    }
    public function get_function_storage(string $function_id): Function_Storage
    {
        if (isset(self::$builtin_functions[$function_id])) {
            return self::$builtin_functions[$function_id];
        }
        throw new UnexpectedValueException('Expecting to have a function for ' . $function_id);
    }
    /**
     * @return array<string, FunctionStorage>
     */
    public function get_functions(): array
    {
        return self::$builtin_functions;
    }
    public static function clear_cache(): void
    {
        self::$builtin_functions = [];
    }
}