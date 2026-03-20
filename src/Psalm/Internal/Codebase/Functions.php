<?php

declare (strict_types=1);
namespace Psalm\Internal\Codebase;

use Exception;
use Php_Parser\Node\Arg;
use Php_Parser\Node\Expr\Closure as ClosureNode;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Method_Identifier;
use Psalm\Internal\Provider\Dynamic_Function_Storage_Provider;
use Psalm\Internal\Provider\File_Storage_Provider;
use Psalm\Internal\Provider\Function_Existence_Provider;
use Psalm\Internal\Provider\Function_Params_Provider;
use Psalm\Internal\Provider\Function_Return_Type_Provider;
use Psalm\Internal\Type\Comparator\Callable_Type_Comparator;
use Psalm\Node_Type_Provider;
use Psalm\Statements_Source;
use Psalm\Storage\Function_Storage;
use Psalm\Type\Atomic\T_Named_Object;
use UnexpectedValueException;
use function array_shift;
use function count;
use function end;
use function explode;
use function implode;
use function in_array;
use function is_bool;
use function rtrim;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strtolower;
use function substr;
/**
 * @internal
 */
final class Functions
{
    /**
     * @var array<lowercase-string, FunctionStorage>
     */
    private static array $stubbed_functions;
    public Function_Return_Type_Provider $return_type_provider;
    public Function_Existence_Provider $existence_provider;
    public Function_Params_Provider $params_provider;
    public Dynamic_Function_Storage_Provider $dynamic_storage_provider;
    public function __construct(private readonly File_Storage_Provider $file_storage_provider, private readonly Reflection $reflection)
    {
        $this->return_type_provider = new Function_Return_Type_Provider();
        $this->existence_provider = new Function_Existence_Provider();
        $this->params_provider = new Function_Params_Provider();
        $this->dynamic_storage_provider = new Dynamic_Function_Storage_Provider();
        self::$stubbed_functions = [];
    }
    /**
     * @param non-empty-lowercase-string $function_id
     */
    public function get_storage(?Statements_Analyzer $statements_analyzer, string $function_id, ?string $root_file_path = null, ?string $checked_file_path = null): Function_Storage
    {
        if ($function_id[0] === '\\') {
            $function_id = substr($function_id, 1);
        }
        if (isset(self::$stubbed_functions[$function_id])) {
            return self::$stubbed_functions[$function_id];
        }
        $file_storage = null;
        if ($statements_analyzer) {
            $root_file_path = $statements_analyzer->get_root_file_path();
            $checked_file_path = $statements_analyzer->get_file_path();
            $file_storage = $this->file_storage_provider->get($root_file_path);
            $function_analyzers = $statements_analyzer->get_function_analyzers();
            if (isset($function_analyzers[$function_id])) {
                $function_id = $function_analyzers[$function_id]->get_function_id();
                if (isset($file_storage->functions[$function_id])) {
                    return $file_storage->functions[$function_id];
                }
            }
            // closures can be returned here
            if (isset($file_storage->functions[$function_id])) {
                return $file_storage->functions[$function_id];
            }
        }
        if (!$root_file_path || !$checked_file_path) {
            if ($this->reflection->has_function($function_id)) {
                return $this->reflection->get_function_storage($function_id);
            }
            throw new UnexpectedValueException('Expecting non-empty $root_file_path and $checked_file_path');
        }
        if ($this->reflection->has_function($function_id)) {
            return $this->reflection->get_function_storage($function_id);
        }
        if (!isset($file_storage->declaring_function_ids[$function_id])) {
            if ($checked_file_path !== $root_file_path) {
                $file_storage = $this->file_storage_provider->get($checked_file_path);
                if (isset($file_storage->functions[$function_id])) {
                    return $file_storage->functions[$function_id];
                }
            }
            throw new UnexpectedValueException('Expecting ' . $function_id . ' to have storage in ' . $checked_file_path);
        }
        $declaring_file_path = $file_storage->declaring_function_ids[$function_id];
        $declaring_file_storage = $this->file_storage_provider->get($declaring_file_path);
        if (!isset($declaring_file_storage->functions[$function_id])) {
            throw new UnexpectedValueException('Not expecting ' . $function_id . ' to not have storage in ' . $declaring_file_path);
        }
        return $declaring_file_storage->functions[$function_id];
    }
    public function add_global_function(string $function_id, Function_Storage $storage): void
    {
        self::$stubbed_functions[strtolower($function_id)] = $storage;
    }
    /**
     * @param array<lowercase-string, FunctionStorage> $stubs
     */
    public function add_global_functions(array $stubs): void
    {
        self::$stubbed_functions += $stubs;
    }
    public function has_stubbed_function(string $function_id): bool
    {
        return isset(self::$stubbed_functions[strtolower($function_id)]);
    }
    /**
     * @return array<lowercase-string, FunctionStorage>
     */
    public function get_all_stubbed_functions(): array
    {
        return self::$stubbed_functions;
    }
    /**
     * @param lowercase-string $function_id
     */
    public function function_exists(Statements_Analyzer $statements_analyzer, string $function_id): bool
    {
        if ($this->existence_provider->has($function_id)) {
            $function_exists = $this->existence_provider->does_function_exist($statements_analyzer, $function_id);
            if ($function_exists !== null) {
                return $function_exists;
            }
        }
        $file_storage = $this->file_storage_provider->get($statements_analyzer->get_root_file_path());
        if (isset($file_storage->declaring_function_ids[$function_id])) {
            return true;
        }
        if ($this->reflection->has_function($function_id)) {
            return true;
        }
        if (isset(self::$stubbed_functions[$function_id])) {
            return true;
        }
        if (isset($statements_analyzer->get_function_analyzers()[$function_id])) {
            return true;
        }
        $predefined_functions = $statements_analyzer->get_codebase()->config->get_predefined_functions();
        if (isset($predefined_functions[$function_id])) {
            /** @psalm-suppress ArgumentTypeCoercion */
            if ($this->reflection->register_function($function_id) === false) {
                return false;
            }
            return true;
        }
        return false;
    }
    /**
     * @param  non-empty-string         $function_name
     * @return non-empty-string
     */
    public function get_fully_qualified_function_name_from_string(string $function_name, Statements_Source $source): string
    {
        if ($function_name[0] === '\\') {
            $function_name = substr($function_name, 1);
            if ($function_name === '') {
                throw new UnexpectedValueException('Malformed function name');
            }
            return $function_name;
        }
        $function_name_lcase = strtolower($function_name);
        $aliases = $source->get_aliases();
        $imported_function_namespaces = $aliases->functions;
        $imported_namespaces = $aliases->uses;
        if (str_contains($function_name, '\\')) {
            $function_name_parts = explode('\\', $function_name);
            $first_namespace = array_shift($function_name_parts);
            $first_namespace_lcase = strtolower($first_namespace);
            if (isset($imported_namespaces[$first_namespace_lcase])) {
                return $imported_namespaces[$first_namespace_lcase] . '\\' . implode('\\', $function_name_parts);
            }
            if (isset($imported_function_namespaces[$first_namespace_lcase])) {
                return $imported_function_namespaces[$first_namespace_lcase] . '\\' . implode('\\', $function_name_parts);
            }
        } elseif (isset($imported_function_namespaces[$function_name_lcase])) {
            return $imported_function_namespaces[$function_name_lcase];
        }
        $namespace = $source->get_namespace();
        return ($namespace ? $namespace . '\\' : '') . $function_name;
    }
    /**
     * @return array<lowercase-string,FunctionStorage>
     */
    public function get_matching_function_names(string $stub, int $offset, string $file_path, Codebase $codebase): array
    {
        if ($stub[0] === '*') {
            $stub = substr($stub, 1);
        }
        $fully_qualified = false;
        if ($stub[0] === '\\') {
            $fully_qualified = true;
            $stub = substr($stub, 1);
            $stub_namespace = '';
        } else {
            // functions can reference either the current namespace or root-namespaced
            // equivalents. We therefore want to make both candidates.
            [$stub_namespace, $stub] = explode('-', $stub);
        }
        /** @var array<lowercase-string, FunctionStorage> */
        $matching_functions = [];
        $file_storage = $this->file_storage_provider->get($file_path);
        $current_namespace_aliases = null;
        foreach ($file_storage->namespace_aliases as $namespace_start => $namespace_aliases) {
            if ($namespace_start < $offset) {
                $current_namespace_aliases = $namespace_aliases;
                break;
            }
        }
        // We will search all functions for several patterns. This will
        // be for all used namespaces, the global namespace and matched
        // used functions.
        $match_function_patterns = [$stub . '*'];
        if ($stub_namespace) {
            $match_function_patterns[] = $stub_namespace . '\\' . $stub . '*';
        }
        if ($current_namespace_aliases) {
            foreach ($current_namespace_aliases->functions as $alias_name => $function_name) {
                if (str_starts_with($alias_name, $stub)) {
                    try {
                        $match_function_patterns[] = $function_name;
                    } catch (Exception) {
                    }
                }
            }
            if (!$fully_qualified) {
                foreach ($current_namespace_aliases->uses as $namespace_name) {
                    $match_function_patterns[] = $namespace_name . '\\' . $stub . '*';
                }
            }
        }
        $function_map = $file_storage->functions + $this->get_all_stubbed_functions() + $this->reflection->get_functions() + $codebase->config->get_predefined_functions();
        foreach ($function_map as $function_name => $function) {
            foreach ($match_function_patterns as $pattern) {
                $pattern_lc = strtolower($pattern);
                if (str_ends_with($pattern, '*')) {
                    if (!str_starts_with($function_name, rtrim($pattern_lc, '*'))) {
                        continue;
                    }
                } elseif ($function_name !== $pattern) {
                    continue;
                }
                if (is_bool($function)) {
                    /** @var callable-string $function_name */
                    if ($this->reflection->register_function($function_name) === false) {
                        continue;
                    }
                    $function = $this->reflection->get_function_storage($function_name);
                }
                if ($function->cased_name) {
                    $cased_name_parts = explode('\\', (string) $function->cased_name);
                    $pattern_parts = explode('\\', $pattern);
                    if (end($cased_name_parts)[0] !== end($pattern_parts)[0]) {
                        continue;
                    }
                }
                /** @var lowercase-string $function_name */
                $matching_functions[$function_name] = $function;
            }
        }
        return $matching_functions;
    }
    public static function is_variadic(Codebase $codebase, string $function_id, string $file_path): bool
    {
        $file_storage = $codebase->file_storage_provider->get($file_path);
        if (!isset($file_storage->declaring_function_ids[$function_id])) {
            return false;
        }
        $declaring_file_path = $file_storage->declaring_function_ids[$function_id];
        $file_storage = $declaring_file_path === $file_path ? $file_storage : $codebase->file_storage_provider->get($declaring_file_path);
        return isset($file_storage->functions[$function_id]) && $file_storage->functions[$function_id]->variadic;
    }
    /**
     * @param ?list<Arg> $args
     */
    public function is_call_map_function_pure(Codebase $codebase, ?Node_Type_Provider $type_provider, string $function_id, ?array $args, bool &$must_use = true): bool
    {
        if (Impure_Functions_List::is_impure($function_id)) {
            return false;
        }
        if ($function_id === 'serialize' && isset($args[0]) && $type_provider) {
            $serialize_type = $type_provider->get_type($args[0]->value);
            if ($serialize_type && $serialize_type->can_contain_object_type($codebase)) {
                return false;
            }
        }
        if (str_starts_with($function_id, 'image')) {
            return false;
        }
        if (str_starts_with($function_id, 'readline')) {
            return false;
        }
        if (($function_id === 'var_export' || $function_id === 'print_r') && !isset($args[1])) {
            return false;
        }
        if ($function_id === 'assert') {
            $must_use = false;
            return true;
        }
        if ($function_id === 'func_num_args' || $function_id === 'func_get_args') {
            return true;
        }
        if (in_array($function_id, ['count', 'sizeof']) && isset($args[0]) && $type_provider) {
            $count_type = $type_provider->get_type($args[0]->value);
            if ($count_type) {
                foreach ($count_type->get_atomic_types() as $atomic_count_type) {
                    if ($atomic_count_type instanceof T_Named_Object) {
                        $count_method_id = new Method_Identifier($atomic_count_type->value, 'count');
                        try {
                            return $codebase->methods->get_storage($count_method_id)->mutation_free;
                        } catch (Exception) {
                            // do nothing
                        }
                    }
                }
            }
        }
        $function_callable = Internal_Call_Map_Handler::get_callable_from_call_map_by_id($codebase, $function_id, $args ?: [], null);
        if (!isset($function_callable->params) || $args !== null && count($args) === 0 || $function_callable->return_type && $function_callable->return_type->is_void()) {
            return false;
        }
        $must_use = $function_id !== 'array_map' || isset($args[0]) && !$args[0]->value instanceof Closure_Node;
        foreach ($function_callable->params as $i => $param) {
            if ($type_provider && $param->type && $param->type->has_callable_type() && isset($args[$i])) {
                $arg_type = $type_provider->get_type($args[$i]->value);
                if ($arg_type) {
                    foreach ($arg_type->get_atomic_types() as $possible_callable) {
                        $possible_callable = Callable_Type_Comparator::get_callable_from_atomic($codebase, $possible_callable);
                        if ($possible_callable && !$possible_callable->is_pure) {
                            return false;
                        }
                    }
                }
            }
            if ($param->by_ref && isset($args[$i])) {
                $must_use = false;
            }
        }
        return true;
    }
    public static function clear_cache(): void
    {
        self::$stubbed_functions = [];
    }
}